<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Schema\Comparison;

use Korioinc\SchemaAlign\Planning\Operation;
use Korioinc\SchemaAlign\Schema\Snapshot;

/** @phpstan-import-type Definition from Operation as OperationDefinition */
final class SchemaComparator
{
    /** @return array{differences: list<OperationDefinition>, errors: list<string>, warnings: list<string>, historical_missing_files: list<string>} */
    public function compare(Snapshot $desired, Snapshot $actual): array
    {
        $result = ['differences' => [], 'errors' => [], 'warnings' => [], 'historical_missing_files' => []];
        foreach (['desired' => $desired, 'actual' => $actual] as $side => $snapshot) {
            foreach ($snapshot->unsupported as $unsupported) {
                $result['errors'][] = $side.' snapshot contains unsupported objects: '.json_encode($unsupported, JSON_THROW_ON_ERROR);
            }
        }

        $result['errors'] = [...$result['errors'], ...$this->platformErrors($desired->platform, $actual->platform)];

        if ($result['errors'] !== []) {
            sort($result['errors']);

            return $result;
        }
        $occupied = $this->relations($actual);
        $reserved = $this->relations($desired);
        foreach ($reserved as $relation => $count) {
            if ($count > 1) {
                $result['errors'][] = 'Desired relation namespace collision: '.$relation;
            }
        }
        $newTables = [];
        $indexColumns = [];
        foreach ($desired->tables as $table => $after) {
            if (! isset($actual->tables[$table])) {
                $parts = [];
                $safe = $after['columns'] !== [] && ($after['drop_column_blockers'] ?? []) === [];
                $notNull = array_filter($after['constraints'], fn (array $constraint): bool => $constraint['kind'] === 'n');
                foreach ($notNull as $constraint) {
                    $safe = $safe && ($constraint['validated'] ?? false) && ! ($constraint['no_inherit'] ?? false)
                        && count($constraint['columns']) === 1 && isset($after['columns'][$constraint['columns'][0]])
                        && ! $after['columns'][$constraint['columns'][0]]['nullable'];
                }
                foreach ($after['columns'] as $name => $column) {
                    $name = (string) $name;
                    $safe = $safe && $this->plainColumn($column);
                    $clause = $column['nullable'] ? '' : ' NOT NULL';
                    foreach ($notNull as $constraintName => $constraint) {
                        if ($constraint['columns'] === [$name]) {
                            $clause = ' CONSTRAINT '.$this->quote((string) $constraintName).' NOT NULL';
                        }
                    }
                    $parts[] = $this->quote($name).' '.$column['type'].$clause;
                }
                $safe = $safe && ! isset($occupied[$after['schema'].'.'.$after['name']]);
                $base = [...$after, 'constraints' => $notNull, 'indexes' => []];
                $op = $this->operation('create_table', $table, $after['name'], null, $base,
                    $safe ? ['CREATE TABLE '.$this->qualified($after).' ('.implode(', ', $parts).')'] : [],
                    $safe ? null : 'New table requires review: unsupported column/default/identity/generated/collation or relation collision.');
                $newTables[$table] = $op;
                $result['differences'][] = $op;
            }
        }
        $newDependencies = array_column($newTables, 'id');
        $allNewSafe = ! in_array(false, array_column($newTables, 'automatic'), true);
        $tableNames = array_unique([...array_keys($desired->tables), ...array_keys($actual->tables)]);
        sort($tableNames);
        foreach ($tableNames as $table) {
            $after = $desired->tables[$table] ?? null;
            $before = $actual->tables[$table] ?? null;

            if ($after === null) {
                $result['differences'][] = $this->operation('extra_table', $table, $before['name'], $before, null, [], 'Destructive removal requires explicit review.');

                continue;
            }
            $new = $before === null;
            $dependencies = $new ? [$newTables[$table]['id']] : [];
            $tableSafe = ! $new || $newTables[$table]['automatic'];

            if (! $new) {
                $missing = array_diff_key($after['columns'], $before['columns']);
                $extra = array_diff_key($before['columns'], $after['columns']);

                if ($missing !== [] && $extra !== []) {
                    $result['warnings'][] = $table.': missing and extra columns require explicit mapping; column renames are not inferred.';
                }
                foreach (array_unique([...array_keys($after['columns']), ...array_keys($before['columns'])]) as $name) {
                    $name = (string) $name;
                    $old = $before['columns'][$name] ?? null;
                    $column = $after['columns'][$name] ?? null;

                    if ($this->canonical($old) === $this->canonical($column)) {
                        continue;
                    }

                    if ($column === null) {
                        $reason = $this->columnRemovalReason($actual, $table, $name);
                        $result['differences'][] = $this->operation($reason === null ? 'drop_column' : 'extra_column', $table, $name, $old, null,
                            $reason === null ? ['ALTER TABLE '.$this->qualified($before).' DROP COLUMN '.$this->quote($name).' RESTRICT'] : [],
                            $reason ?? 'Column removal deletes stored values and requires --allow-drop-columns at apply.');

                        continue;
                    }
                    $safe = $old === null && $column['nullable'] && $this->plainColumn($column);
                    $kind = $old === null ? 'add_column' : 'change_column';
                    $result['differences'][] = $this->operation($kind, $table, $name, $old, $column,
                        $safe ? ['ALTER TABLE '.$this->qualified($after).' ADD COLUMN '.$this->quote($name).' '.$column['type']] : [],
                        $safe ? null : 'Required additions, defaults, type, identity, generated and collation changes require explicit review.');
                }
            }
            foreach (['constraints', 'indexes'] as $collection) {
                $wanted = $after[$collection];
                $existing = $before[$collection] ?? ($collection === 'constraints' ? $newTables[$table]['after']['constraints'] : []);
                $singular = $collection === 'indexes' ? 'index' : 'constraint';
                // Same-name objects are compared before semantic rename candidates.
                foreach (array_intersect_key($wanted, $existing) as $name => $object) {
                    $name = (string) $name;

                    if ($this->semantic($object) !== $this->semantic($existing[$name])
                        || ($object['backing_index'] ?? null) !== ($existing[$name]['backing_index'] ?? null)) {
                        $result['differences'][] = $this->operation('change_'.$singular, $table, $name, $existing[$name], $object, [], 'Same-name definition or backing index name differs; explicit review is required.');
                    }
                }
                $missing = array_diff_key($wanted, $existing);
                $extra = array_diff_key($existing, $wanted);
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
                    $object = $missing[$name];
                    $old = $extra[$oldName];
                    $relationRename = $collection === 'indexes' || ($old['backing_index'] ?? null) !== null;
                    $safe = ! $relationRename || ! isset($occupied[$after['schema'].'.'.$name]);

                    if ($collection === 'constraints' && $relationRename) {
                        $safe = $safe && ($object['backing_index'] ?? null) === $name;
                    }
                    // PostgreSQL renames a constraint's backing index to the constraint's new name.
                    $safe = $safe && (! $relationRename || ($reserved[$after['schema'].'.'.$name] ?? 0) <= 1);
                    $sql = $collection === 'indexes'
                        ? 'ALTER INDEX '.$this->quote($after['schema']).'.'.$this->quote($oldName).' RENAME TO '.$this->quote($name)
                        : 'ALTER TABLE '.$this->qualified($after).' RENAME CONSTRAINT '.$this->quote($oldName).' TO '.$this->quote($name);
                    $rename = $this->operation('rename_'.$singular, $table, $name, $old, $object, $safe ? [$sql] : [], $safe ? null : 'Rename target collides with a relation or cannot preserve the desired backing index name.');
                    $rename['old_name'] = $oldName;
                    $result['differences'][] = $rename;
                    unset($missing[$name], $extra[$oldName]);
                }
                foreach ($missing as $name => $object) {
                    $name = (string) $name;
                    $sql = [];
                    $reason = 'Adding a constraint or unique/expression index requires explicit validation.';
                    $columns = $collection === 'indexes' ? $this->safeIndexColumns($object, $name, $after) : null;

                    if ($collection === 'indexes' && $tableSafe && $columns !== null
                        && ! isset($occupied[$after['schema'].'.'.$name])) {
                        $sql = [$object['definition']];
                    } elseif ($collection === 'constraints' && $new && $tableSafe) {
                        $definition = $object['definition'];
                        $key = $this->simpleKey($object, $after);
                        $foreign = ($object['kind'] ?? '') === 'f' && $this->safeForeignKey($definition, $desired);
                        $backing = in_array($object['kind'] ?? '', ['p', 'u'], true);

                        if (($key || ($foreign && $allNewSafe)) && (! $backing || (($object['backing_index'] ?? null) === $name && ! isset($occupied[$after['schema'].'.'.$name])))) {
                            $sql = ['ALTER TABLE '.$this->qualified($after).' ADD CONSTRAINT '.$this->quote($name).' '.$definition];
                        }
                    }
                    $deps = ($object['kind'] ?? '') === 'f' ? $newDependencies : $dependencies;
                    $operation = $this->operation($collection === 'indexes' ? 'create_index' : 'add_constraint', $table, $name, null, $object, $sql, $sql !== [] ? null : $reason, $deps);
                    $result['differences'][] = $operation;

                    if ($columns !== null) {
                        $indexColumns[$operation['id']] = $columns;
                    }
                }
                foreach ($extra as $name => $object) {
                    $name = (string) $name;
                    $result['differences'][] = $this->operation('extra_'.$singular, $table, $name, $object, null, [], 'Destructive removal requires explicit review.');
                }
            }
        }

        if (array_diff_key($desired->tables, $actual->tables) !== [] && array_diff_key($actual->tables, $desired->tables) !== []) {
            $result['warnings'][] = 'Missing and extra tables require explicit mapping; table renames are not inferred.';
        }
        // Indexes need their columns; foreign keys need the referenced unique keys.
        $operationsByTable = [];
        $operationOffsets = [];
        foreach ($result['differences'] as $offset => $operation) {
            $operationsByTable[$operation['table']][] = $offset;
            $operationOffsets[$operation['id']] = $offset;
        }
        foreach ($result['differences'] as &$dependent) {
            $prerequisites = $this->foreignKeyDependencies($dependent, $actual, $result['differences'], $operationsByTable);

            if ($dependent['kind'] === 'create_index') {
                foreach ($operationsByTable[$dependent['table']] as $offset) {
                    $operation = $result['differences'][$offset];

                    if (in_array($operation['kind'], ['add_column', 'change_column'], true)
                        && in_array($operation['name'], $indexColumns[$dependent['id']] ?? [], true)) {
                        $prerequisites[] = $operation['id'];
                    }
                }
            }
            foreach ($prerequisites as $id) {
                $prerequisite = $result['differences'][$operationOffsets[$id]];
                $dependent['dependencies'][] = $id;

                if (! $prerequisite['automatic']) {
                    $dependent['automatic'] = false;
                    $dependent['sql'] = [];
                    $dependent['reason'] = 'A prerequisite column or key requires manual review.';
                }
            }
            $dependent['dependencies'] = array_values(array_unique($dependent['dependencies']));
            sort($dependent['dependencies']);
        }
        unset($dependent);
        $result['historical_missing_files'] = array_values(array_diff($actual->migrationNames, $desired->migrationNames));
        foreach (['errors', 'warnings', 'historical_missing_files'] as $key) {
            sort($result[$key]);
        }

        if ($result['errors'] !== []) {
            foreach ($result['differences'] as &$difference) {
                $difference['automatic'] = false;
                $difference['sql'] = [];
                $difference['reason'] ??= 'Comparison contains blocking errors.';
            }
            unset($difference);
        }
        usort($result['differences'], fn (array $a, array $b): int => [$a['table'], $a['kind'], $a['name']] <=> [$b['table'], $b['kind'], $b['name']]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $platform
     * @return array<string, mixed>
     */
    private function platformSignature(array $platform): array
    {
        // Occupied relation names are schema state, not platform compatibility requirements.
        unset($platform['sequences'], $platform['excluded_relations']);

        if (isset($platform['server_major'])) {
            unset($platform['server_version'], $platform['server_version_num']);
        }

        return $platform;
    }

    /**
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $actual
     * @return list<string>
     */
    private function platformErrors(array $desired, array $actual): array
    {
        $errors = [];

        if ($desired === [] || $actual === []) {
            $errors[] = 'Snapshot platform metadata is missing; inspect both PostgreSQL platforms before applying.';
        }
        foreach (['desired' => $desired, 'actual' => $actual] as $side => $platform) {
            $database = $platform['database'] ?? null;

            if (is_array($database)) {
                $this->checkCollationVersion($database, 'collation_version', 'actual_collation_version', $side.' platform.database', $errors);
            }
            $collations = $platform['collations'] ?? [];

            if (is_array($collations)) {
                foreach ($collations as $name => $collation) {
                    if (is_array($collation)) {
                        $this->checkCollationVersion($collation, 'collversion', 'actual_version', $side.' platform.collations['.Snapshot::canonical($name).']', $errors);
                    }
                }
            }
        }
        $this->platformDifferences($this->platformSignature($desired), $this->platformSignature($actual), 'platform', $errors);
        $total = count($errors);
        $errors = array_slice($errors, 0, 12);

        if ($total > 12) {
            $errors[] = ($total - 12).' additional platform diagnostics omitted; inspect snapshot platform metadata.';
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @param  list<string>  $errors
     */
    private function checkCollationVersion(array $metadata, string $recorded, string $actual, string $path, array &$errors): void
    {
        // pg_catalog.default records its version on pg_database, not pg_collation.
        if (($metadata['nspname'] ?? null) === 'pg_catalog' && ($metadata['collname'] ?? null) === 'default'
            && ($metadata['collprovider'] ?? null) === 'd') {
            return;
        }

        if (array_key_exists($recorded, $metadata) && array_key_exists($actual, $metadata) && $metadata[$recorded] !== $metadata[$actual]) {
            $errors[] = $path.'.'.$recorded.' is stale: recorded='.$this->platformValue($metadata[$recorded])
                .', '.$actual.'='.$this->platformValue($metadata[$actual]).'; rebuild affected indexes and refresh collation versions before applying.';
        }
    }

    /** @param list<string> $errors */
    private function platformDifferences(mixed $desired, mixed $actual, string $path, array &$errors): void
    {
        if (Snapshot::canonical($desired) === Snapshot::canonical($actual)) {
            return;
        }

        if (is_array($desired) && is_array($actual)) {
            $keys = array_unique([...array_keys($desired), ...array_keys($actual)]);
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $field = $path.(is_string($key) && preg_match('/\A[a-zA-Z_][a-zA-Z_0-9]*\z/D', $key) === 1
                    ? '.'.$key : '['.Snapshot::canonical($key).']');

                if (! array_key_exists($key, $desired) || ! array_key_exists($key, $actual)) {
                    $errors[] = $field.': expected='.(array_key_exists($key, $desired) ? $this->platformValue($desired[$key]) : '<missing>')
                        .', actual='.(array_key_exists($key, $actual) ? $this->platformValue($actual[$key]) : '<missing>');
                } else {
                    $this->platformDifferences($desired[$key], $actual[$key], $field, $errors);
                }
            }

            return;
        }
        $errors[] = $path.': expected='.$this->platformValue($desired).', actual='.$this->platformValue($actual);
    }

    private function platformValue(mixed $value): string
    {
        $json = Snapshot::canonical($value);

        return strlen($json) > 240 ? substr($json, 0, 240).'... [truncated]' : $json;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** @param array<string, mixed> $table */
    private function qualified(array $table): string
    {
        return $this->quote($table['schema']).'.'.$this->quote($table['name']);
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                $item = $this->canonical($item);
            }
            unset($item);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $object */
    private function semantic(array $object): string
    {
        return $this->canonical($object['signature']);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $sql
     * @param  list<string>  $dependencies
     * @return OperationDefinition
     */
    private function operation(string $kind, string $table, string $name, ?array $before, ?array $after, array $sql, ?string $reason, array $dependencies = []): array
    {
        sort($dependencies);

        return ['id' => substr(hash('sha256', $this->canonical([$kind, $table, $name, $before, $after, $sql])), 0, 24),
            'kind' => $kind, 'table' => $table, 'name' => $name, 'before' => $before, 'after' => $after,
            'sql' => $sql, 'automatic' => $sql !== [] && $kind !== 'drop_column', 'reason' => $reason, 'transactional' => true, 'dependencies' => $dependencies];
    }

    private function columnRemovalReason(Snapshot $actual, string $table, string $name): ?string
    {
        $before = $actual->tables[$table];

        if (! $this->plainColumn($before['columns'][$name])) {
            return 'Column removal with defaults, identity, generated expressions or special types requires manual review.';
        }

        if (($before['drop_column_blockers'] ?? []) !== []) {
            return 'Column removal on a table with extended statistics requires manual review.';
        }
        foreach ($before['columns'] as $column) {
            if (($column['generated'] ?? '') !== '') {
                return 'Column removal on a table with generated columns requires manual dependency review.';
            }
        }
        $indexes = array_values($before['indexes']);
        foreach ($before['constraints'] as $constraint) {
            if (in_array($name, $constraint['columns'], true)) {
                return 'Column removal affects a constraint and requires manual review.';
            }

            if (isset($constraint['index'])) {
                $indexes[] = $constraint['index'];
            }
        }
        foreach ($indexes as $index) {
            if (($index['predicate'] ?? null) !== null || in_array($name, $index['include'], true)) {
                return 'Column removal may affect an index or predicate and requires manual review.';
            }
            foreach ($index['keys'] as $key) {
                if (($key['column'] ?? null) === null || $key['column'] === $name) {
                    return 'Column removal may affect an index or expression and requires manual review.';
                }
            }
        }
        foreach ($actual->tables as $other) {
            foreach ($other['constraints'] as $constraint) {
                if ($constraint['kind'] === 'f'
                    && ($constraint['foreign_schema'] ?? null) === $before['schema']
                    && ($constraint['foreign_table'] ?? null) === $before['name']
                    && in_array($name, $constraint['foreign_columns'] ?? [], true)) {
                    return 'Column removal affects a referenced foreign key and requires manual review.';
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $column */
    private function plainColumn(array $column): bool
    {
        return ($column['identity'] ?? '') === '' && ($column['generated'] ?? '') === ''
            && ($column['default'] ?? null) === null && in_array($column['collation'] ?? null, [null, 'pg_catalog.default', 'pg_catalog."default"'], true)
            && preg_match('/\A(?:smallint|integer|bigint|boolean|text|bytea|uuid|date|real|double precision|json|jsonb|character varying(?:\([1-9][0-9]*\))?|character(?:\([1-9][0-9]*\))?|numeric(?:\([1-9][0-9]*(?:,[0-9]+)?\))?|(?:timestamp|time)(?:\([0-6]\))? (?:with|without) time zone)\z/D', $column['type']) === 1;
    }

    /** @return array<string, int> PostgreSQL tables, sequences and all indexes share a namespace. */
    private function relations(Snapshot $snapshot): array
    {
        $relations = [];
        foreach (['sequences', 'excluded_relations'] as $collection) {
            foreach ($snapshot->platform[$collection] ?? [] as $relation) {
                $key = $relation['schema'].'.'.$relation['name'];
                $relations[$key] = ($relations[$key] ?? 0) + 1;
            }
        }
        foreach ($snapshot->tables as $table) {
            $names = [$table['name'], ...array_keys($table['indexes'])];
            foreach ($table['constraints'] as $constraint) {
                if (($constraint['backing_index'] ?? null) !== null) {
                    $names[] = $constraint['backing_index'];
                }
            }
            foreach ($names as $name) {
                $key = $table['schema'].'.'.$name;
                $relations[$key] = ($relations[$key] ?? 0) + 1;
            }
        }

        return $relations;
    }

    private function identifierPattern(): string
    {
        return '(?:"(?:[^"]|"")+"|[a-z_][a-z0-9_$]*)';
    }

    private function decodeIdentifier(string $identifier): string
    {
        return str_starts_with($identifier, '"') ? str_replace('""', '"', substr($identifier, 1, -1)) : $identifier;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $table
     */
    private function simpleKey(array $object, array $table): bool
    {
        if (! in_array($object['kind'] ?? '', ['p', 'u'], true) || ($object['columns'] ?? []) === []) {
            return false;
        }
        $id = $this->identifierPattern();
        $prefix = $object['kind'] === 'p' ? 'PRIMARY KEY' : 'UNIQUE';

        if (! preg_match('/\A'.$prefix.' \(('.$id.'(?:, '.$id.')*)\)\z/D', $object['definition'], $match)) {
            return false;
        }
        preg_match_all('/'.$id.'/', $match[1], $columns);
        $names = array_map($this->decodeIdentifier(...), $columns[0]);

        return $names === $object['columns'] && array_diff($names, array_keys($table['columns'])) === [];
    }

    private function safeForeignKey(string $definition, Snapshot $desired): bool
    {
        $reference = $this->foreignKeyReference($definition);

        return $reference !== null && isset($desired->tables[$reference['table']]);
    }

    /** @return array{table: string, columns: list<string>}|null */
    private function foreignKeyReference(string $definition): ?array
    {
        $id = $this->identifierPattern();
        $list = $id.'(?:, '.$id.')*';

        if (! preg_match('/\AFOREIGN KEY \('.$list.'\) REFERENCES ('.$id.')(?:\.('.$id.'))? ?\(('.$list.')\)(?: MATCH (?:FULL|SIMPLE))?(?: ON UPDATE (?:NO ACTION|RESTRICT|CASCADE|SET NULL|SET DEFAULT))?(?: ON DELETE (?:NO ACTION|RESTRICT|CASCADE|SET NULL|SET DEFAULT))?(?: DEFERRABLE(?: INITIALLY (?:DEFERRED|IMMEDIATE))?)?\z/D', $definition, $match)) {
            return null;
        }

        // An unqualified reference depends on search_path and is deliberately manual.
        if ($match[2] === '') {
            return null;
        }
        preg_match_all('/'.$id.'/', $match[3], $columns);

        return ['table' => $this->decodeIdentifier($match[1]).'.'.$this->decodeIdentifier($match[2]),
            'columns' => array_map($this->decodeIdentifier(...), $columns[0])];
    }

    /**
     * @param  OperationDefinition  $dependent
     * @param  list<OperationDefinition>  $operations
     * @param  array<string, list<int>>  $operationsByTable
     * @return list<string>
     */
    private function foreignKeyDependencies(array $dependent, Snapshot $actual, array $operations, array $operationsByTable): array
    {
        if ($dependent['kind'] !== 'add_constraint' || ($dependent['after']['kind'] ?? '') !== 'f') {
            return [];
        }
        $reference = $this->foreignKeyReference($dependent['after']['definition']);

        if ($reference === null) {
            return [];
        }
        $dependencies = [];
        $offsets = $operationsByTable[$reference['table']] ?? [];
        foreach ($offsets as $offset) {
            $operation = $operations[$offset];

            if (in_array($operation['kind'], ['add_column', 'change_column'], true)
                && in_array($operation['name'], $reference['columns'], true)) {
                $dependencies[] = $operation['id'];
            }
        }
        $table = $actual->tables[$reference['table']] ?? [];
        foreach ([...array_values($table['constraints'] ?? []), ...array_values($table['indexes'] ?? [])] as $key) {
            if ($this->supportsReference($key, $reference['columns'])) {
                return $dependencies;
            }
        }
        $dependency = null;
        foreach ($offsets as $offset) {
            $operation = $operations[$offset];

            if (! in_array($operation['kind'], ['add_constraint', 'change_constraint', 'create_index', 'change_index'], true)
                || ! $this->supportsReference($operation['after'], $reference['columns'])) {
                continue;
            }

            // Any suitable key can supply the reference; prefer one the plan can create.
            if ($operation['automatic']) {
                return [...$dependencies, $operation['id']];
            }
            $dependency ??= $operation['id'];
        }

        return $dependency === null ? $dependencies : [...$dependencies, $dependency];
    }

    /**
     * @param  array<string, mixed>|null  $key
     * @param  list<string>  $columns
     */
    private function supportsReference(?array $key, array $columns): bool
    {
        if (in_array($key['kind'] ?? '', ['p', 'u'], true)) {
            if ($key['deferrable'] ?? false) {
                return false;
            }
            $keyColumns = $key['columns'];
        } elseif (($key['unique'] ?? false) && ($key['predicate'] ?? null) === null) {
            $keyColumns = array_map(fn (mixed $item): mixed => is_array($item) ? ($item['column'] ?? null) : $item, $key['keys']);
        } else {
            return false;
        }

        if (in_array(null, $keyColumns, true)) {
            return false;
        }
        sort($keyColumns, SORT_STRING);
        sort($columns, SORT_STRING);

        return $keyColumns === $columns;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $table
     * @return list<string>|null
     */
    private function safeIndexColumns(array $object, string $name, array $table): ?array
    {
        if (($object['unique'] ?? true) || ($object['method'] ?? '') !== 'btree'
            || ($object['predicate'] ?? null) !== null || ($object['include'] ?? []) !== []
            || ($object['nulls_not_distinct'] ?? false)) {
            return null;
        }
        $id = $this->identifierPattern();
        $key = $id.'(?: (?:ASC|DESC))?(?: NULLS (?:FIRST|LAST))?';

        if (! preg_match('/\ACREATE INDEX ('.$id.') ON ('.$id.')\.('.$id.') USING btree \(('.$key.'(?:, '.$key.')*)\)\z/D', $object['definition'], $match)) {
            return null;
        }

        if ($this->decodeIdentifier($match[1]) !== $name || $this->decodeIdentifier($match[2]) !== $table['schema'] || $this->decodeIdentifier($match[3]) !== $table['name']) {
            return null;
        }
        preg_match_all('/(?:\A|, )('.$id.')(?: (?:ASC|DESC))?(?: NULLS (?:FIRST|LAST))?(?=, |\z)/', $match[4], $keys);
        $columns = [];
        foreach ($keys[1] as $column) {
            $columnName = $this->decodeIdentifier($column);
            $column = $table['columns'][$columnName] ?? null;

            if ($column === null || ! $this->plainColumn($column)) {
                return null;
            }
            $columns[] = $columnName;
        }

        return $columns !== [] ? $columns : null;
    }
}
