<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Schema\Catalog;

use InvalidArgumentException;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Snapshot;
use PDO;
use RuntimeException;
use Throwable;

/** Reads the bounded, logical MySQL schema contract from server metadata. */
final class MySqlCatalog
{
    /** @param array<string, mixed>|null $ownedReplay Verified replay resource, including its complete marker. */
    public function __construct(private PDO $pdo, private string $migrationTable = 'migrations', private ?array $ownedReplay = null)
    {
        if ($migrationTable === '' || str_contains($migrationTable, "\0")) {
            throw new InvalidArgumentException('Invalid migration table identifier.');
        }
    }

    public function inspect(): Snapshot
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('MySqlCatalog requires PDO MySQL.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $server = $this->rows('SELECT VERSION() AS version, @@version_comment AS product')[0];
        $version = (string) $server['version'];

        if (! preg_match('/\A(8\.(?:0|4))\.(\d+)(?:[-+].*)?\z/', $version, $match)
            || ($match[1] === '8.0' && (int) $match[2] < 20)
            || ! preg_match('/\AMySQL (?:Community|Enterprise) Server/', (string) $server['product'])) {
            throw new RuntimeException('Only genuine MySQL 8.0.20+ (8.0 or 8.4 series) is supported.');
        }
        $ownsTransaction = ! $this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }

        try {
            $identity = $this->rows('SELECT @@server_uuid AS server_uuid, DATABASE() AS `database`, CURRENT_USER() AS `current_user`, USER() AS session_user, @@hostname AS host, @@port AS port, VERSION() AS version, @@version_comment AS product')[0];

            if (! is_string($identity['database']) || $identity['database'] === '') {
                throw new RuntimeException('MySQL catalog requires a selected database.');
            }
            $database = $identity['database'];
            $ignoreMarker = $this->ownedReplay !== null;

            if ($ignoreMarker) {
                $this->verifyReplayMarker($database, $identity);
            }
            $settings = $this->rows('SELECT @@sql_mode AS sql_mode, @@lower_case_table_names AS lower_case_table_names, @@character_set_server AS character_set_server, @@collation_server AS collation_server, @@default_storage_engine AS default_storage_engine, @@SESSION.character_set_client AS character_set_client, @@SESSION.character_set_connection AS character_set_connection, @@SESSION.character_set_results AS character_set_results, @@SESSION.collation_connection AS collation_connection')[0];
            // MySQL introduced GIPK mode in 8.0.30; older supported releases cannot enable it.
            $settings['sql_generate_invisible_primary_key'] = $match[1] === '8.4' || (int) $match[2] >= 30
                ? (int) $this->rows('SELECT @@SESSION.sql_generate_invisible_primary_key AS enabled')[0]['enabled'] : 0;
            $modes = array_filter(explode(',', strtoupper((string) $settings['sql_mode'])));
            sort($modes, SORT_STRING);
            $settings['sql_mode'] = implode(',', $modes);
            $settings['lower_case_table_names'] = (int) $settings['lower_case_table_names'];
            $migrationTableKey = $settings['lower_case_table_names'] === 0 ? null
                : $this->rows('SELECT HEX(LOWER(CONVERT(? USING utf8) COLLATE utf8_general_ci)) AS identifier_key', [$this->migrationTable])[0]['identifier_key'];

            if ($migrationTableKey === strtoupper(bin2hex('__schema_align_run'))) {
                throw new RuntimeException('Migration ledger aliases the reserved replay ownership marker.');
            }
            $defaults = $this->rows('SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]);
            $platform = ['engine' => 'mysql', 'server_series' => $match[1], 'server_version' => $version, 'settings' => $settings, 'database' => $defaults[0] ?? []];
            $unsupported = $this->visibility($database);
            $gipkVisibility = $this->rows("SHOW VARIABLES LIKE 'show_gipk_in_create_table_and_information_schema'");

            if ($gipkVisibility !== [] && strtoupper((string) $gipkVisibility[0]['Value']) !== 'ON') {
                $unsupported[] = ['reason' => 'metadata_visibility', 'object' => '@database', 'missing' => ['visible generated invisible primary keys']];
            }
            foreach ($this->rows('SELECT DISTINCT TABLE_NAME FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ? AND PARTITION_NAME IS NOT NULL ORDER BY TABLE_NAME', [$database]) as $partition) {
                $unsupported[] = ['reason' => 'partition', 'object' => '@database.'.$partition['TABLE_NAME']];
            }
            foreach (['TRIGGERS' => ['TRIGGER_SCHEMA', 'TRIGGER_NAME', 'trigger'], 'ROUTINES' => ['ROUTINE_SCHEMA', 'ROUTINE_NAME', 'routine'], 'EVENTS' => ['EVENT_SCHEMA', 'EVENT_NAME', 'event']] as $source => [$schema, $name, $reason]) {
                foreach ($this->rows("SELECT `$name` AS name FROM information_schema.$source WHERE `$schema` = ? ORDER BY `$name`", [$database]) as $row) {
                    $unsupported[] = ['reason' => $reason, 'object' => '@database.'.$row['name']];
                }
            }
            $tables = [];
            $migrationNames = [];
            $seenMigrations = [];
            $relations = $this->rows('SELECT t.TABLE_NAME, HEX(LOWER(CONVERT(t.TABLE_NAME USING utf8) COLLATE utf8_general_ci)) AS IDENTIFIER_KEY, t.TABLE_TYPE, t.ENGINE, t.ROW_FORMAT, t.TABLE_COLLATION, t.TABLE_COMMENT, t.CREATE_OPTIONS, c.CHARACTER_SET_NAME FROM information_schema.TABLES t LEFT JOIN information_schema.COLLATIONS c ON c.COLLATION_NAME = t.TABLE_COLLATION WHERE t.TABLE_SCHEMA = ? ORDER BY t.TABLE_NAME', [$database]);
            foreach (array_chunk($relations, 100) as $group) {
                $names = [];
                foreach ($group as $relation) {
                    if ($relation['TABLE_TYPE'] === 'BASE TABLE' && (! $ignoreMarker || $relation['TABLE_NAME'] !== '__schema_align_run')) {
                        $names[] = (string) $relation['TABLE_NAME'];
                    }
                }
                $metadata = $names === [] ? [] : $this->tableMetadata($database, $names);
                foreach ($group as $row) {
                    $name = (string) $row['TABLE_NAME'];

                    if ($ignoreMarker && $name === '__schema_align_run') {
                        continue;
                    }

                    if ($row['TABLE_TYPE'] !== 'BASE TABLE') {
                        $unsupported[] = ['reason' => 'view', 'object' => '@database.'.$name];

                        continue;
                    }
                    $columns = $this->columns($name, $metadata['columns'][$name] ?? [], $unsupported);

                    if ($name === $this->migrationTable || ($migrationTableKey !== null && $row['IDENTIFIER_KEY'] === $migrationTableKey)) {
                        if (array_diff(['id', 'migration', 'batch'], array_keys($columns)) !== []) {
                            throw new RuntimeException('Migration ledger is missing required columns.');
                        }
                        foreach ($this->rows('SELECT migration FROM '.$this->quote($name).' ORDER BY migration') as $migration) {
                            if (! is_string($migration['migration']) || $migration['migration'] === '' || isset($seenMigrations['migration:'.$migration['migration']])) {
                                throw new RuntimeException('Invalid or duplicate migration ledger name.');
                            }
                            $seenMigrations['migration:'.$migration['migration']] = true;
                            $migrationNames[] = $migration['migration'];
                        }

                        continue;
                    }
                    $options = trim((string) $row['CREATE_OPTIONS']);
                    // DEFAULT ENCRYPTION='N' is a harmless explicit server default.
                    $options = trim((string) preg_replace('/(?:^|\s)ENCRYPTION=[\x27\x22]N[\x27\x22](?=\s|$)/i', '', $options));
                    $format = strtoupper((string) $row['ROW_FORMAT']);
                    $supportedFormat = in_array($format, ['DYNAMIC', 'COMPACT', 'REDUNDANT'], true);

                    if ($supportedFormat) {
                        // Explicit row format is already represented by ROW_FORMAT in the snapshot.
                        $options = trim((string) preg_replace('/(?:^|\s)ROW_FORMAT='.$format.'(?=\s|$)/i', '', $options));
                    }

                    if ($row['ENGINE'] !== 'InnoDB' || $options !== '' || $row['TABLE_COMMENT'] !== '' || ! $supportedFormat) {
                        $unsupported[] = ['reason' => 'table_properties', 'object' => '@database.'.$name, 'engine' => $row['ENGINE'], 'options' => $options, 'comment' => $row['TABLE_COMMENT'], 'row_format' => $row['ROW_FORMAT']];
                    }
                    // Explicit tablespaces do not consistently appear in CREATE_OPTIONS.
                    $create = array_values($this->rows('SHOW CREATE TABLE '.$this->quote($name))[0]);

                    if ($this->hasExplicitTablespace((string) $create[1])) {
                        $unsupported[] = ['reason' => 'tablespace', 'object' => '@database.'.$name];
                    }
                    foreach ($metadata['indexes'][$name] ?? [] as $indexProperty) {
                        if ($indexProperty['INDEX_TYPE'] === 'FULLTEXT') {
                            $unsupported[] = ['reason' => 'fulltext_parser', 'object' => '@database.'.$name.'.'.$indexProperty['INDEX_NAME']];
                        } elseif ($indexProperty['INDEX_NAME'] === 'PRIMARY' && ($indexProperty['SUB_PART'] !== null || $indexProperty['COLLATION'] === 'D')) {
                            $unsupported[] = ['reason' => 'primary_key_properties', 'object' => '@database.'.$name.'.PRIMARY', 'prefix' => $indexProperty['SUB_PART'], 'order' => $indexProperty['COLLATION']];
                        }
                    }
                    $tables['@database.'.$name] = ['schema' => '@database', 'name' => $name, 'identifier_key' => (string) $row['IDENTIFIER_KEY'], 'engine' => $row['ENGINE'], 'charset' => $row['CHARACTER_SET_NAME'], 'collation' => $row['TABLE_COLLATION'], 'row_format' => $row['ROW_FORMAT'], 'comment' => $row['TABLE_COMMENT'], 'columns' => $columns, 'constraints' => $this->constraints($database, $name, $metadata['constraints'][$name] ?? [], $metadata['keys'][$name] ?? [], $unsupported), 'indexes' => $this->indexes($metadata['indexes'][$name] ?? [])];
                }
                unset($metadata);
            }
            $snapshot = new Snapshot($identity, $platform, $tables, $unsupported, $migrationNames);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $snapshot;
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                $this->rollback();
            }

            throw $error;
        }
    }

    private function hasExplicitTablespace(string $create): bool
    {
        $quote = null;
        $depth = 0;
        $definitionClosed = false;
        $length = strlen($create);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $create[$offset];

            if ($quote !== null) {
                // SHOW CREATE escapes string backslashes even with NO_BACKSLASH_ESCAPES.
                // Quoted identifiers only double their delimiter; their backslashes are literal.
                if ($quote === "'" && $character === '\\') {
                    $offset++;
                } elseif ($character === $quote) {
                    if (($create[$offset + 1] ?? '') === $quote) {
                        $offset++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                $definitionClosed = $definitionClosed || $depth === 0;
            } elseif ($definitionClosed && $depth === 0 && ($character === 'T' || $character === 't')
                && preg_match('/\G\bTABLESPACE\b/i', $create, offset: $offset)) {
                // Keep executable version comments: MySQL emits the real option inside one.
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tables
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function tableMetadata(string $database, array $tables): array
    {
        // MySQL identifier comparison uses the utf8mb3_general_ci lowercase
        // case table, not its accent-insensitive collation weights. Keep the
        // resulting bytes (HEX) for offline comparison and signed projections.
        // The utf8 spelling also supports MySQL 8.0.20, before the collation rename.
        // mysql-server/strings/ctype-utf8.cc: my_strcasecmp_utf8mb3.
        $scope = ' IN ('.implode(', ', array_fill(0, count($tables), '?')).')';
        $queries = [
            'columns' => 'SELECT TABLE_NAME, COLUMN_NAME, HEX(LOWER(CONVERT(COLUMN_NAME USING utf8) COLLATE utf8_general_ci)) AS IDENTIFIER_KEY, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION, COLLATION_NAME, CHARACTER_SET_NAME, COLUMN_COMMENT, SRS_ID FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME'.$scope.' ORDER BY TABLE_NAME, COLUMN_NAME',
            'constraints' => 'SELECT t.TABLE_NAME, t.CONSTRAINT_NAME, t.CONSTRAINT_TYPE, t.ENFORCED, r.UPDATE_RULE, r.DELETE_RULE, r.MATCH_OPTION, c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND r.TABLE_NAME = t.TABLE_NAME AND r.CONSTRAINT_NAME = t.CONSTRAINT_NAME LEFT JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA = ? AND t.TABLE_NAME'.$scope.' ORDER BY t.TABLE_NAME, t.CONSTRAINT_NAME',
            'keys' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME'.$scope.' ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            'indexes' => 'SELECT TABLE_NAME, INDEX_NAME, HEX(LOWER(CONVERT(INDEX_NAME USING utf8) COLLATE utf8_general_ci)) AS IDENTIFIER_KEY, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME, EXPRESSION, SUB_PART, COLLATION, IS_VISIBLE, INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME'.$scope.' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        ];
        $metadata = [];
        foreach ($queries as $kind => $sql) {
            foreach ($this->rows($sql, [$database, ...$tables]) as $row) {
                $metadata[$kind][(string) $row['TABLE_NAME']][] = $row;
            }
        }

        return $metadata;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  list<array<string, mixed>>  $unsupported
     * @return array<string, array<string, mixed>>
     */
    private function columns(string $table, array $rows, array &$unsupported): array
    {
        $columns = [];
        foreach ($rows as $row) {
            $extra = strtolower((string) $row['EXTRA']);
            preg_match('/on update (current_timestamp(?:\(\d+\))?)/i', $extra, $update);
            $remaining = trim((string) preg_replace('/default_generated|auto_increment|stored generated|virtual generated|invisible|on update current_timestamp(?:\(\d+\))?/i', '', $extra));

            if ($remaining !== '' || $row['SRS_ID'] !== null || str_contains($extra, 'invisible') || $row['COLUMN_COMMENT'] !== '') {
                $unsupported[] = ['reason' => 'column_properties', 'object' => '@database.'.$table.'.'.$row['COLUMN_NAME'], 'extra' => $extra, 'srs_id' => $row['SRS_ID'], 'comment' => $row['COLUMN_COMMENT']];
            }
            $default = $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'];
            $expression = str_contains($extra, 'default_generated');

            if ($expression && $default !== null && preg_match('/\Acurrent_timestamp(?:\(\d+\))?\z/i', $default)) {
                $default = strtoupper($default);
            }
            $columns[(string) $row['COLUMN_NAME']] = ['identifier_key' => (string) $row['IDENTIFIER_KEY'], 'type' => (string) $row['COLUMN_TYPE'], 'nullable' => $row['IS_NULLABLE'] === 'YES', 'default' => $default, 'default_kind' => $default === null ? 'none' : ($expression ? 'expression' : 'literal'), 'identity' => str_contains($extra, 'auto_increment') ? 'd' : '', 'generated' => str_contains($extra, 'stored generated') ? 's' : (str_contains($extra, 'virtual generated') ? 'v' : ''), 'generation_expression' => (string) $row['GENERATION_EXPRESSION'], 'on_update' => isset($update[1]) ? strtoupper($update[1]) : '', 'collation' => $row['COLLATION_NAME'], 'charset' => $row['CHARACTER_SET_NAME'], 'comment' => $row['COLUMN_COMMENT'], 'invisible' => str_contains($extra, 'invisible'), 'unsigned' => preg_match('/\bunsigned\b/i', (string) $row['COLUMN_TYPE']) === 1];
        }

        return $columns;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  list<array<string, mixed>>  $keyRows
     * @param  list<array<string, mixed>>  $unsupported
     * @return array<string, array<string, mixed>>
     */
    private function constraints(string $database, string $table, array $rows, array $keyRows, array &$unsupported): array
    {
        $constraints = [];
        $keysByConstraint = [];
        foreach ($keyRows as $key) {
            $keysByConstraint[(string) $key['CONSTRAINT_NAME']][] = $key;
        }
        foreach ($rows as $row) {
            if ($row['CONSTRAINT_TYPE'] === 'UNIQUE') {
                continue;
            }
            $name = (string) $row['CONSTRAINT_NAME'];
            $keys = $keysByConstraint[$name] ?? [];

            if ($row['CONSTRAINT_TYPE'] !== 'CHECK' && $keys === []) {
                throw new RuntimeException('Constraint column metadata is missing.');
            }
            $value = ['kind' => 'p', 'columns' => array_column($keys, 'COLUMN_NAME'), 'backing_index' => 'PRIMARY'];

            if ($row['CONSTRAINT_TYPE'] === 'FOREIGN KEY') {
                if (! is_string($row['UPDATE_RULE']) || ! is_string($row['DELETE_RULE']) || ! is_string($row['MATCH_OPTION'])) {
                    throw new RuntimeException('Foreign key rule metadata is missing.');
                }
                $foreign = $keys[0];
                $external = $foreign['REFERENCED_TABLE_SCHEMA'] !== $database;
                $value = ['kind' => 'f', 'columns' => array_column($keys, 'COLUMN_NAME'), 'backing_index' => null, 'foreign_schema' => $external ? $foreign['REFERENCED_TABLE_SCHEMA'] : '@database', 'foreign_table' => $foreign['REFERENCED_TABLE_NAME'], 'foreign_columns' => array_column($keys, 'REFERENCED_COLUMN_NAME'), 'on_update' => $row['UPDATE_RULE'], 'on_delete' => $row['DELETE_RULE'], 'match' => $row['MATCH_OPTION']];

                if ($external) {
                    $unsupported[] = ['reason' => 'external_foreign_key', 'object' => '@database.'.$table.'.'.$name];
                }
            } elseif ($row['CONSTRAINT_TYPE'] === 'CHECK') {
                if (! is_string($row['CHECK_CLAUSE'])) {
                    throw new RuntimeException('Check constraint metadata is missing.');
                }
                $value = ['kind' => 'c', 'columns' => [], 'backing_index' => null, 'expression' => $row['CHECK_CLAUSE'], 'enforced' => $row['ENFORCED'] === 'YES'];
            }
            $value['definition'] = $value['kind'] === 'c' ? 'CHECK('.$value['expression'].')' : Snapshot::canonical($value);
            $value['signature'] = Snapshot::canonical($value);
            $constraints[$name] = $value;
        }

        return $constraints;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexes(array $rows): array
    {
        $indexes = [];
        $identifierKeys = [];
        foreach ($rows as $row) {
            if ($row['INDEX_NAME'] === 'PRIMARY') {
                continue;
            }
            $name = (string) $row['INDEX_NAME'];
            $identifierKeys[$name] = (string) $row['IDENTIFIER_KEY'];
            $indexes[$name] ??= ['method' => $row['INDEX_TYPE'], 'keys' => [], 'include' => [], 'predicate' => null, 'unique' => (int) $row['NON_UNIQUE'] === 0, 'nulls_not_distinct' => false, 'invisible' => $row['IS_VISIBLE'] === 'NO', 'comment' => $row['INDEX_COMMENT']];
            $indexes[$name]['keys'][] = ['column' => $row['COLUMN_NAME'], 'expression' => $row['EXPRESSION'], 'prefix' => $row['SUB_PART'] === null ? null : (int) $row['SUB_PART'], 'order' => $row['COLLATION'] === 'D' ? 'DESC' : 'ASC'];
        }
        foreach ($indexes as $name => &$index) {
            $index['definition'] = Snapshot::canonical($index);
            $index['signature'] = Snapshot::canonical($index);
            $index['identifier_key'] = $identifierKeys[$name];
        }
        unset($index);

        return $indexes;
    }

    /** Prove schema-wide visibility; table grants and unexpanded roles fail closed.
     * @return list<array<string, mixed>>
     */
    private function visibility(string $database): array
    {
        $global = TargetConnection::mysqlGlobalPrivileges($this->pdo);
        $schema = $this->databasePrivileges($database);
        $required = ['SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT', 'EXECUTE'];
        $missing = in_array('ALL PRIVILEGES', $global, true) ? [] : array_values(array_diff($required, array_merge($global, $schema)));

        if (array_intersect(['SELECT', 'SUPER', 'SHOW_ROUTINE', 'ALL PRIVILEGES'], $global) === []) {
            $missing[] = 'global SHOW_ROUTINE (or global SELECT / SUPER)';
        }

        return $missing === [] ? [] : [['reason' => 'metadata_visibility', 'object' => '@database', 'missing' => $missing]];
    }

    /** Only privileges guaranteed by every matching database grant are safe to use.
     * MySQL selects the first matching grant, rather than combining overlapping patterns.
     *
     * @return list<string>
     */
    private function databasePrivileges(string $database): array
    {
        $settings = $this->rows('SELECT @@partial_revokes AS partial_revokes')[0]['partial_revokes'];

        if (! in_array($settings, [0, 1, '0', '1'], true)) {
            throw new RuntimeException('Unknown MySQL partial_revokes setting.');
        }
        $literal = (int) $settings === 1;
        $grants = TargetConnection::mysqlGrants($this->pdo);

        if (count($grants) > 1024) {
            throw new RuntimeException('MySQL grant evidence exceeds the supported bound.');
        }
        $effective = null;
        $supported = ['ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW', 'DELETE', 'DROP', 'EVENT', 'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT', 'SHOW VIEW', 'TRIGGER', 'UPDATE'];
        // The shared global parser has already validated every complete statement and
        // refused roles, partial revokes and unknown scopes. Match complete rows again
        // so identifiers containing SQL-looking text cannot supply privileges.
        $pattern = <<<'REGEX'
~\AGRANT (?<privileges>[A-Z_ ,]+) ON `(?<database>(?:``|[^`])+)`\.\* TO (?:`(?:``|[^`])*`|'(?:''|[^'])*')@(?:`(?:``|[^`])*`|'(?:''|[^'])*')(?: WITH GRANT OPTION)?\z~iD
REGEX;
        foreach ($grants as $grant) {
            if (strlen($grant) > 65536) {
                throw new RuntimeException('MySQL grant evidence exceeds the supported bound.');
            }

            if (! preg_match($pattern, $grant, $match)) {
                continue;
            }
            $name = str_replace('``', '`', $match['database']);

            if (strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new RuntimeException('Unsupported MySQL database grant identifier.');
            }
            $privileges = array_map(static fn (string $privilege): string => strtoupper(trim($privilege)), explode(',', $match['privileges']));

            if ($privileges === ['ALL PRIVILEGES']) {
                $privileges = $supported;
            } elseif (array_diff($privileges, $supported) !== []) {
                throw new RuntimeException('Unknown MySQL database privilege evidence.');
            }

            if ($this->matchesDatabaseGrant($database, $name, $literal)) {
                $effective = $effective === null ? $privileges : array_values(array_intersect($effective, $privileges));
            }
        }

        return $effective ?? [];
    }

    private function matchesDatabaseGrant(string $database, string $grant, bool $literal): bool
    {
        if ($literal) {
            return $database === $grant;
        }
        $pattern = '';
        for ($offset = 0, $length = strlen($grant); $offset < $length; $offset++) {
            $character = $grant[$offset];

            if ($character === '\\') {
                if (++$offset === $length) {
                    throw new RuntimeException('Incomplete MySQL database grant escape.');
                }
                $pattern .= preg_quote($grant[$offset], '~');
            } else {
                $pattern .= match ($character) {
                    '%' => '.*',
                    '_' => '.',
                    default => preg_quote($character, '~'),
                };
            }
        }
        $matches = preg_match('~\A'.$pattern.'\z~usD', $database);

        if ($matches === false) {
            throw new RuntimeException('Invalid MySQL database grant pattern.');
        }

        return $matches === 1;
    }

    /** @param array<string, mixed> $identity */
    private function verifyReplayMarker(string $database, array $identity): void
    {
        $resource = $this->ownedReplay;

        if (($resource['version'] ?? null) !== 1 || ($resource['engine'] ?? null) !== 'mysql'
            || ! is_string($resource['run_id'] ?? null) || preg_match('/\A[a-f0-9]{32}\z/', $resource['run_id']) !== 1
            || ! is_array($resource['schema_map'] ?? null) || ! in_array($database, $resource['schema_map'], true)
            || ! is_array($resource['namespaces'] ?? null) || ! in_array($database, $resource['namespaces'], true)
            || $resource['schema_map'] !== ['@database' => $database] || $resource['namespaces'] !== [$database]
            || preg_match('/\Aalign-[0-9]{4}-[a-f0-9]{5}\z/', $database) !== 1
            || ! is_string($resource['binding'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $resource['binding']) !== 1
            || ! is_string($resource['created_at'] ?? null) || ($resource['namespace_oids'] ?? null) !== []
            || ! in_array($resource['state'] ?? null, ['owned', 'running'], true) || ! is_array($resource['sessions'] ?? null)
            || ! is_array($resource['identity'] ?? null) || ($resource['identity']['database'] ?? null) === $database
            || ($resource['identity']['server_uuid'] ?? null) !== $identity['server_uuid']) {
            throw new RuntimeException('Invalid MySQL replay ownership resource.');
        }
        $columns = $this->rows('SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$database, '__schema_align_run']);
        $tables = $this->rows('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, '__schema_align_run']);

        if ($columns !== [['COLUMN_NAME' => 'payload', 'DATA_TYPE' => 'longtext', 'IS_NULLABLE' => 'NO']]
            || $tables !== [['TABLE_TYPE' => 'BASE TABLE']]) {
            throw new RuntimeException('MySQL replay ownership marker has an invalid table shape.');
        }
        $rows = $this->rows('SELECT payload FROM '.$this->quote($database).'.`__schema_align_run`');

        if (count($rows) !== 1 || ! is_string($rows[0]['payload'])) {
            throw new RuntimeException('MySQL replay ownership marker must contain exactly one JSON row.');
        }
        $marker = json_decode($rows[0]['payload'], true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($marker) || Snapshot::canonical($marker) !== Snapshot::canonical($resource)) {
            throw new RuntimeException('MySQL replay ownership marker does not match the supplied resource.');
        }
    }

    /** @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
