<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Schema\Comparison;

use Korioinc\SchemaAlign\Planning\Operation;
use Korioinc\SchemaAlign\Schema\Snapshot;

/** @phpstan-import-type Definition from Operation as OperationDefinition */
final class MySqlComparator
{
    /** @return array{differences: list<OperationDefinition>, errors: list<string>, warnings: list<string>, historical_missing_files: list<string>} */
    public function compare(Snapshot $desired, Snapshot $actual): array
    {
        $result = ['differences' => [], 'errors' => [], 'warnings' => [], 'historical_missing_files' => array_values(array_diff($actual->migrationNames, $desired->migrationNames))];
        foreach (['desired' => $desired, 'actual' => $actual] as $side => $snapshot) {
            $tableNames = [];
            foreach ($snapshot->unsupported as $object) {
                $result['errors'][] = $side.' snapshot contains unsupported objects: '.Snapshot::canonical($object);
            }

            if (($snapshot->platform['engine'] ?? null) !== 'mysql' || ! is_string($snapshot->platform['server_series'] ?? null)) {
                $result['errors'][] = $side.' snapshot requires MySQL engine and server_series metadata.';
            }
            foreach (['settings' => ['sql_mode', 'lower_case_table_names', 'character_set_server', 'collation_server', 'default_storage_engine'], 'database' => ['charset', 'collation']] as $group => $fields) {
                foreach ($fields as $field) {
                    if (! is_array($snapshot->platform[$group] ?? null) || ! array_key_exists($field, $snapshot->platform[$group])) {
                        $result['errors'][] = $side.' platform.'.$group.'.'.$field.' metadata is missing.';
                    }
                }
            }

            if (! in_array($snapshot->platform['settings']['lower_case_table_names'] ?? null, [0, 1, 2], true)) {
                $result['errors'][] = $side.' snapshot requires lower_case_table_names to be 0, 1 or 2.';
            }
            foreach ($snapshot->tables as $key => $table) {
                if (($table['schema'] ?? null) !== '@database' || $key !== '@database.'.($table['name'] ?? '')) {
                    $result['errors'][] = $side.' table must use the @database schema placeholder: '.$key;
                }

                if (($snapshot->platform['settings']['lower_case_table_names'] ?? 0) !== 0) {
                    $folded = $this->identifierKey($table['name'], $table);

                    if ($folded !== null) {
                        if (isset($tableNames[$folded])) {
                            $result['errors'][] = $side.' table name collision: '.$key;
                        }
                        $tableNames[$folded] = true;
                    }
                }
                foreach (['columns', 'indexes'] as $collection) {
                    $names = [];
                    foreach ($table[$collection] as $name => $object) {
                        $folded = $this->identifierKey((string) $name, $object);

                        if ($folded === null) {
                            continue;
                        }

                        if (isset($names[$folded])) {
                            $result['errors'][] = $side.' table-local '.$collection.' name collision: '.$key.'.'.$name;
                        }
                        $names[$folded] = true;
                    }
                }
            }
        }
        $wantedPlatform = $desired->platform;
        $existingPlatform = $actual->platform;
        unset($wantedPlatform['server_version'], $existingPlatform['server_version']);
        foreach (array_unique([...array_keys($wantedPlatform), ...array_keys($existingPlatform)]) as $field) {
            if (! array_key_exists($field, $wantedPlatform) || ! array_key_exists($field, $existingPlatform)
                || Snapshot::canonical($wantedPlatform[$field]) !== Snapshot::canonical($existingPlatform[$field])) {
                $result['errors'][] = 'platform.'.$field.': expected='.Snapshot::canonical($wantedPlatform[$field] ?? null).', actual='.Snapshot::canonical($existingPlatform[$field] ?? null);
            }
        }

        if ($result['errors'] !== []) {
            sort($result['errors']);
            sort($result['historical_missing_files']);

            return $result;
        }
        foreach ($desired->tables as $table => $after) {
            $before = $actual->tables[$table] ?? null;

            if ($before === null) {
                [$sql, $base] = $this->createTable($after);
                $nameAvailable = $actual->platform['settings']['lower_case_table_names'] === 0
                    || $this->tableNameAvailable($after, $actual->tables);
                $create = $this->operation('create_table', $table, $after['name'], null, $base, $sql === null || ! $nameAvailable ? [] : [$sql],
                    ! $nameAvailable ? 'Table name collides with an existing table or lacks server identifier metadata; explicit review is required.' : ($sql === null ? 'New table requires review of columns, keys or table options.' : null));
                $result['differences'][] = $create;
                $existing = $base;
            } else {
                $existing = $before;
                $create = null;

                if (Snapshot::canonical($this->tableOptions($after)) !== Snapshot::canonical($this->tableOptions($before))) {
                    $result['differences'][] = $this->operation('change_table', $table, $after['name'], $this->tableOptions($before), $this->tableOptions($after), [], 'Table option changes require explicit review.');
                }

                if (array_diff_key($after['columns'], $before['columns']) !== [] && array_diff_key($before['columns'], $after['columns']) !== []) {
                    $result['warnings'][] = $table.': missing and extra columns require explicit mapping; renames are not inferred.';
                }
                foreach (array_unique([...array_keys($after['columns']), ...array_keys($before['columns'])]) as $name) {
                    $name = (string) $name;
                    $old = $before['columns'][$name] ?? null;
                    $column = $after['columns'][$name] ?? null;

                    if ($old !== null && $column !== null && $this->semantic($old) === $this->semantic($column)) {
                        continue;
                    }
                    $clause = $column === null ? null : $this->columnSql($name, $column, $after, false);
                    $nameAvailable = $column !== null && $this->tableLocalNameAvailable($name, $column, $before['columns']);
                    $safe = $old === null && $nameAvailable && ($column['nullable'] ?? false) && $clause !== null && $this->ordinaryTable($after) && $this->ordinaryTable($before)
                        && Snapshot::canonical($this->tableOptions($after)) === Snapshot::canonical($this->tableOptions($before));
                    $result['differences'][] = $this->operation($old === null ? 'add_column' : ($column === null ? 'extra_column' : 'change_column'), $table, $name, $old, $column,
                        $safe ? ['ALTER TABLE '.$this->quote($after['name']).' ADD COLUMN '.$clause] : [],
                        $safe ? null : ($old === null && ! $nameAvailable ? 'Column name collides with an existing column or lacks server identifier metadata; explicit review is required.' : 'Column changes, required additions, defaults or special attributes require explicit review.'));
                }
            }
            foreach (['constraints', 'indexes'] as $collection) {
                $wanted = $after[$collection];
                $current = $existing[$collection];
                $singular = $collection === 'indexes' ? 'index' : 'constraint';
                foreach (array_intersect_key($wanted, $current) as $name => $object) {
                    $name = (string) $name;

                    if ($this->semantic($object) !== $this->semantic($current[$name])) {
                        $result['differences'][] = $this->operation('change_'.$singular, $table, $name, $current[$name], $object, [], 'Same-name definition differs; explicit review is required.');
                    }
                }
                $missing = array_diff_key($wanted, $current);
                $extra = array_diff_key($current, $wanted);
                $missingSemantics = [];
                $missingCounts = [];
                $extraMatches = [];

                if ($missing !== [] && $extra !== []) {
                    foreach ($missing as $name => $object) {
                        $semantic = $this->semantic($object);
                        $missingSemantics[$name] = $semantic;
                        $missingCounts[$semantic] = ($missingCounts[$semantic] ?? 0) + 1;
                    }
                    foreach ($extra as $oldName => $old) {
                        $semantic = $this->semantic($old);
                        $extraMatches[$semantic] ??= ['count' => 0, 'name' => $oldName];
                        $extraMatches[$semantic]['count']++;
                    }
                }
                foreach ($missingSemantics as $name => $semantic) {
                    $name = (string) $name;
                    $match = $extraMatches[$semantic] ?? null;

                    if ($match === null) {
                        continue;
                    }

                    if ($missingCounts[$semantic] !== 1 || $match['count'] !== 1) {
                        $result['errors'][] = $table.': ambiguous '.$singular.' rename for '.$name;

                        continue;
                    }
                    $oldName = (string) $match['name'];
                    $safe = $collection === 'indexes' && $this->ordinaryIndexName($name, $missing[$name]) && $this->ordinaryIndexName($oldName, $extra[$oldName])
                        && $this->tableLocalNameAvailable($name, $missing[$name], $current, $oldName);
                    $op = $this->operation('rename_'.$singular, $table, $name, $extra[$oldName], $missing[$name],
                        $safe ? ['ALTER TABLE '.$this->quote($after['name']).' RENAME INDEX '.$this->quote($oldName).' TO '.$this->quote($name)] : [],
                        $safe ? null : 'Constraint or PRIMARY renames require explicit review.');
                    $op['old_name'] = $oldName;
                    $result['differences'][] = $op;
                    unset($missing[$name], $extra[$oldName]);
                }
                foreach ($missing as $name => $object) {
                    $name = (string) $name;
                    $keys = $collection === 'indexes' ? $this->indexKeys($object, $after) : null;
                    $replacesForeignKeyIndex = $keys !== null && $this->mayReplaceForeignKeyIndex($object, $existing['indexes']);
                    $safe = $keys !== null && ! ($object['unique'] ?? true) && $this->ordinaryTable($after)
                        && ($create === null || $create['automatic']) && $this->ordinaryIndexName($name, $object)
                        && $this->tableLocalNameAvailable($name, $object, $current) && ! $replacesForeignKeyIndex;
                    $deps = $create === null ? [] : [$create['id']];
                    $result['differences'][] = $this->operation($collection === 'indexes' ? 'create_index' : 'add_constraint', $table, $name, null, $object,
                        $safe ? ['CREATE INDEX '.$this->quote($name).' ON '.$this->quote($after['name']).' ('.$keys.')'] : [],
                        $safe ? null : ($replacesForeignKeyIndex ? 'Index creation may implicitly remove an existing foreign key index; explicit review is required.' : 'Constraint, unique or expression index additions require explicit validation; foreign keys require dependency review.'), $deps);
                }
                foreach ($extra as $name => $object) {
                    $name = (string) $name;
                    $result['differences'][] = $this->operation('extra_'.$singular, $table, $name, $object, null, [], 'Destructive removal requires explicit review.');
                }
            }
        }
        foreach (array_diff_key($actual->tables, $desired->tables) as $table => $before) {
            $result['differences'][] = $this->operation('extra_table', $table, $before['name'], $before, null, [], 'Destructive removal requires explicit review.');
        }

        if (array_diff_key($desired->tables, $actual->tables) !== [] && array_diff_key($actual->tables, $desired->tables) !== []) {
            $result['warnings'][] = 'Missing and extra tables require explicit mapping; table renames are not inferred.';
        }
        $prerequisitesByTable = [];
        foreach ($result['differences'] as $offset => $operation) {
            if (in_array($operation['kind'], ['add_column', 'change_column', 'change_table'], true)) {
                $prerequisitesByTable[$operation['table']][] = $offset;
            }
        }
        foreach ($result['differences'] as &$op) {
            if ($op['kind'] === 'create_index') {
                $columns = array_column($op['after']['keys'] ?? [], 'column');
                foreach ($prerequisitesByTable[$op['table']] ?? [] as $offset) {
                    $prerequisite = $result['differences'][$offset];

                    if ($prerequisite['kind'] !== 'change_table' && ! in_array($prerequisite['name'], $columns, true)) {
                        continue;
                    }
                    $op['dependencies'][] = $prerequisite['id'];

                    if (! $prerequisite['automatic']) {
                        $op['automatic'] = false;
                        $op['sql'] = [];
                        $op['reason'] = 'A prerequisite column or table requires manual review.';
                    }
                }
            }

            if ($result['errors'] !== []) {
                $op['automatic'] = false;
                $op['sql'] = [];
                $op['reason'] ??= 'Comparison contains blocking errors.';
            }
            sort($op['dependencies']);
        }
        unset($op);
        foreach (['errors', 'warnings', 'historical_missing_files'] as $field) {
            sort($result[$field]);
        }
        usort($result['differences'], fn (array $a, array $b): int => [$a['table'], $a['kind'], $a['name']] <=> [$b['table'], $b['kind'], $b['name']]);

        return $result;
    }

    /** @param array<string, mixed> $table
     * @return array<string, mixed>
     */
    private function tableOptions(array $table): array
    {
        unset($table['columns'], $table['constraints'], $table['indexes'], $table['schema'], $table['name'], $table['identifier_key']);

        return $table;
    }

    /** @param array<string, mixed> $table */
    private function ordinaryTable(array $table): bool
    {
        return ($table['engine'] ?? null) === 'InnoDB';
    }

    /** @param array<string, mixed> $table
     * @return array{?string, array<string, mixed>}
     */
    private function createTable(array $table): array
    {
        $base = [...$table, 'constraints' => [], 'indexes' => []];
        $parts = [];
        $safe = $this->ordinaryTable($table) && $table['columns'] !== [] && ($table['comment'] ?? '') === '';
        foreach (['columns', 'indexes'] as $collection) {
            if (count($table[$collection]) < 2) {
                continue;
            }
            foreach ($table[$collection] as $name => $object) {
                // Known collisions were rejected during snapshot validation.
                $safe = $safe && $this->identifierKey((string) $name, $object) !== null;
            }
        }
        $identities = [];
        foreach ($table['columns'] as $name => $column) {
            $name = (string) $name;
            $clause = $this->columnSql($name, $column, $table, true);
            $safe = $safe && $clause !== null;
            $parts[] = $clause ?? '';

            if (($column['identity'] ?? '') !== '') {
                $identities[] = $name;
            }
        }
        foreach ($table['constraints'] as $name => $constraint) {
            $columns = $constraint['columns'] ?? [];

            if ($name === 'PRIMARY' && ($constraint['kind'] ?? '') === 'p' && $columns !== []
                && array_diff($columns, array_keys($table['columns'])) === []
                && count($columns) === count(array_unique($columns))
                && array_filter($columns, fn (string $column): bool => $table['columns'][$column]['nullable']) === []) {
                $parts[] = 'PRIMARY KEY ('.implode(', ', array_map($this->quote(...), $columns)).')';
                $base['constraints'][$name] = $constraint;
            }
        }

        if ($identities !== []) {
            $primary = $base['constraints']['PRIMARY']['columns'] ?? [];
            $safe = $safe && count($identities) === 1 && ($primary[0] ?? null) === $identities[0];
        }
        foreach ($table['indexes'] as $name => $index) {
            $name = (string) $name;
            $keys = $this->indexKeys($index, $table);

            if (($index['unique'] ?? false) && $keys !== null && $this->ordinaryIndexName($name, $index)) {
                $parts[] = 'UNIQUE KEY '.$this->quote($name).' ('.$keys.')';
                $base['indexes'][$name] = $index;
            }
        }
        $safe = $safe && array_diff(array_keys($this->tableOptions($table)), ['engine', 'charset', 'collation', 'row_format', 'comment']) === [];
        $options = ' ENGINE=InnoDB';
        foreach (['charset' => ' DEFAULT CHARACTER SET ', 'collation' => ' COLLATE '] as $field => $prefix) {
            $value = $table[$field] ?? null;

            if (! is_string($value) || preg_match('/\A[a-zA-Z0-9_]+\z/D', $value) !== 1) {
                $safe = false;
            } else {
                $options .= $prefix.$value;
            }
        }
        $format = strtoupper($table['row_format'] ?? '');

        if (! in_array($format, ['DYNAMIC', 'COMPACT', 'REDUNDANT'], true)) {
            $safe = false;
        } else {
            $options .= ' ROW_FORMAT='.$format;
        }

        if (! $safe) {
            return [null, $table];
        }

        return ['CREATE TABLE '.$this->quote($table['name']).' ('.implode(', ', $parts).')'.$options, $base];
    }

    /** @param array<string, mixed> $column
     * @param  array<string, mixed>  $table
     */
    private function columnSql(string $name, array $column, array $table, bool $new): ?string
    {
        $type = $column['type'] ?? '';

        if (! is_string($type) || preg_match('/\A(?:(?:tinyint|smallint|mediumint|int|bigint)(?:\([1-9][0-9]*\))?(?: unsigned)?|(?:decimal|numeric)\([1-9][0-9]*(?:,[0-9]+)?\)(?: unsigned)?|float|double|date|year|(?:datetime|timestamp|time)(?:\([0-6]\))?|(?:var)?char\([1-9][0-9]*\)|(?:var)?binary\([1-9][0-9]*\)|(?:tiny|medium|long)?(?:text|blob)|json|bit\([1-9][0-9]*\))\z/D', $type) !== 1
            || ($column['generated'] ?? '') !== '' || ($column['generation_expression'] ?? '') !== ''
            || ($column['on_update'] ?? '') !== '' || ($column['comment'] ?? '') !== '' || ($column['invisible'] ?? false)
            || ($column['default'] ?? null) !== null || ! in_array($column['default_kind'] ?? 'none', ['none', 'literal'], true)
            || ! is_bool($column['nullable'] ?? null)) {
            return null;
        }
        foreach (['charset', 'collation'] as $field) {
            if (($column[$field] ?? null) !== null && $column[$field] !== ($table[$field] ?? null)) {
                return null;
            }
        }

        if (str_starts_with($type, 'timestamp') && ! $column['nullable']) {
            return null;
        }
        $identity = $column['identity'] ?? '';

        if ($identity !== '' && (! $new || $identity !== 'd' || $column['nullable'] || preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)(?:\([1-9][0-9]*\))?(?: unsigned)?\z/D', $type) !== 1)) {
            return null;
        }

        return $this->quote($name).' '.$type.($column['nullable'] ? ' NULL' : ' NOT NULL').($identity === 'd' ? ' AUTO_INCREMENT' : '');
    }

    /** @param array<string, mixed> $index
     * @param  array<string, mixed>  $table
     */
    private function indexKeys(array $index, array $table): ?string
    {
        if (($index['method'] ?? null) !== 'BTREE' || ($index['include'] ?? []) !== [] || ($index['predicate'] ?? null) !== null
            || ($index['nulls_not_distinct'] ?? false) || ($index['invisible'] ?? false) || ($index['comment'] ?? '') !== '' || ($index['keys'] ?? []) === []) {
            return null;
        }
        $parts = [];
        foreach ($index['keys'] as $key) {
            if (! is_array($key) || ! is_string($key['column'] ?? null) || ! isset($table['columns'][$key['column']])
                || ($key['expression'] ?? null) !== null || ! in_array($key['order'] ?? null, ['ASC', 'DESC'], true)) {
                return null;
            }
            $column = $table['columns'][$key['column']];
            $type = $column['type'];
            $prefix = $key['prefix'] ?? null;

            if (($column['generated'] ?? '') !== '' || $type === 'json'
                || (preg_match('/\A(?:tiny|medium|long)?(?:text|blob)\z/D', $type) === 1 && $prefix === null)) {
                return null;
            }

            if ($prefix !== null && (! is_int($prefix) || $prefix < 1
                || preg_match('/\A(?:(?:var)?(?:char|binary)\(|(?:tiny|medium|long)?(?:text|blob)\z)/D', $table['columns'][$key['column']]['type']) !== 1)) {
                return null;
            }
            $parts[] = $this->quote($key['column']).($prefix === null ? '' : '('.$prefix.')').' '.$key['order'];
        }

        return implode(', ', $parts);
    }

    /** @param array<string, mixed> $index
     * @param  array<string, array<string, mixed>>  $indexes
     */
    private function mayReplaceForeignKeyIndex(array $index, array $indexes): bool
    {
        foreach ($indexes as $existing) {
            if (($existing['unique'] ?? true) || ($existing['method'] ?? null) !== 'BTREE' || ($existing['keys'] ?? []) === []) {
                continue;
            }
            foreach ($existing['keys'] as $position => $key) {
                $candidate = $index['keys'][$position] ?? [];

                if (! is_string($key['column'] ?? null) || ($key['prefix'] ?? null) !== null || ($key['expression'] ?? null) !== null
                    || ($candidate['column'] ?? null) !== $key['column'] || ($candidate['prefix'] ?? null) !== null || ($candidate['expression'] ?? null) !== null) {
                    continue 2;
                }
            }

            // Generated FK indexes can be removed even after the foreign key is dropped.
            // The catalog cannot distinguish their provenance from explicit indexes.
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $object
     * @param  array<string, array<string, mixed>>  $objects
     */
    private function tableLocalNameAvailable(string $name, array $object, array $objects, ?string $oldName = null): bool
    {
        $folded = $this->identifierKey($name, $object);
        foreach ($objects as $occupied => $existing) {
            $occupied = (string) $occupied;

            if ($occupied === $oldName) {
                continue;
            }
            $existingKey = $this->identifierKey($occupied, $existing);

            if ($folded === null || $existingKey === null || $existingKey === $folded) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $table
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function tableNameAvailable(array $table, array $tables): bool
    {
        $folded = $this->identifierKey($table['name'], $table);

        if ($folded === null) {
            return false;
        }
        foreach ($tables as $existing) {
            $existingKey = $this->identifierKey($existing['name'], $existing);

            if ($existingKey === null || $existingKey === $folded) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $object */
    private function identifierKey(string $name, array $object): ?string
    {
        if (array_key_exists('identifier_key', $object)) {
            $key = $object['identifier_key'];

            return is_string($key) && preg_match('/\A(?:[0-9A-F]{2})+\z/D', $key) === 1 ? $key : null;
        }

        // Legacy ASCII snapshots remain comparable. Unicode requires the server's
        // case table, which differs from PHP and from MySQL collation equality.
        return preg_match('/\A[\x00-\x7f]+\z/D', $name) === 1 ? strtoupper(bin2hex(strtolower($name))) : null;
    }

    /** @param array<string, mixed> $index */
    private function ordinaryIndexName(string $name, array $index): bool
    {
        $key = $this->identifierKey($name, $index);

        return $key !== null && $key !== '7072696D617279'; // MySQL's lowercase bytes for PRIMARY.
    }

    /** @param array<string, mixed> $object */
    private function semantic(array $object): string
    {
        unset($object['definition'], $object['identifier_key']);

        return Snapshot::canonical($object);
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $sql
     * @param  list<string>  $dependencies
     * @return OperationDefinition
     */
    private function operation(string $kind, string $table, string $name, ?array $before, ?array $after, array $sql, ?string $reason, array $dependencies = []): array
    {
        return ['id' => substr(hash('sha256', Snapshot::canonical([$kind, $table, $name, $before, $after, $sql])), 0, 24),
            'kind' => $kind, 'table' => $table, 'name' => $name, 'before' => $before, 'after' => $after,
            'sql' => $sql, 'automatic' => $sql !== [], 'reason' => $reason, 'transactional' => false, 'dependencies' => $dependencies];
    }
}
