<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Schema\Catalog;

use InvalidArgumentException;
use Korioinc\SchemaAlign\Schema\Snapshot;
use PDO;
use RuntimeException;
use Throwable;

/** A deliberately bounded catalog reader, not a general PostgreSQL schema dumper. */
final class PostgresCatalog
{
    /** @param list<string> $schemas */
    public function __construct(private PDO $pdo, private array $schemas = ['public'], private string $migrationTable = 'migrations')
    {
        if ($schemas === []) {
            throw new InvalidArgumentException('At least one schema is required.');
        }
        foreach ([...$schemas, $migrationTable] as $identifier) {
            if ($identifier === '' || str_contains($identifier, "\0")) {
                throw new InvalidArgumentException('Invalid catalog identifier.');
            }
        }
        $this->schemas = array_values(array_unique($schemas));
        sort($this->schemas, SORT_STRING);
    }

    public function inspect(): Snapshot
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('PostgresCatalog requires PDO PostgreSQL.');
        }

        $ownsTransaction = ! $this->pdo->inTransaction();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($ownsTransaction) {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $version = (int) $this->rows("SELECT current_setting('server_version_num') AS version")[0]['version'];

            if ($version < 180000) {
                throw new RuntimeException('PostgreSQL 18 or newer is required.');
            }
            // Pin deparser visibility and literal output without changing session state after commit.
            $this->pdo->exec("SET LOCAL search_path = pg_catalog; SET LOCAL TimeZone = 'UTC'; SET LOCAL DateStyle = 'ISO, YMD'; SET LOCAL IntervalStyle = 'postgres'; SET LOCAL extra_float_digits = 3; SET LOCAL standard_conforming_strings = on");
            $identity = $this->rows(<<<'SQL'
SELECT current_database() AS database, current_user AS current_user,
       inet_server_addr()::text AS server_addr, inet_server_port() AS server_port,
       d.oid::text AS database_oid, version() AS version
FROM pg_database d WHERE d.datname = current_database()
SQL)[0];
            $platform = $this->platform($version);
            // Sequences share the relation namespace, including those owned by the excluded migration ledger.
            $platform['sequences'] = [];
            foreach ($this->scoped(<<<'SQL'
SELECT n.nspname AS schema, c.relname AS name
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE __SCOPE__ AND c.relkind = 'S'
ORDER BY n.nspname, c.relname
SQL) as $sequence) {
                $platform['sequences'][$sequence['schema'].'.'.$sequence['name']] = $sequence;
            }
            $unsupported = $this->unsupported();
            $tables = [];
            $tableOids = [];
            $migrationNames = [];
            $platform['excluded_relations'] = [];
            $ledgerFound = false;
            foreach ($this->scoped(<<<'SQL'
SELECT c.oid::text AS oid, n.nspname AS schema, c.relname AS name, c.relkind,
       EXISTS (SELECT 1 FROM pg_statistic_ext s WHERE s.stxrelid = c.oid) AS has_extended_statistics
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE __SCOPE__ AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
ORDER BY n.nspname, c.relname
SQL) as $relation) {
                $schema = (string) $relation['schema'];
                $name = (string) $relation['name'];

                if ($name === $this->migrationTable) {
                    if ($ledgerFound) {
                        throw new RuntimeException('Migration ledger is ambiguous across selected schemas.');
                    }
                    $ledgerFound = true;
                    $migrationNames = $this->migrationNames($schema, $name, (string) $relation['relkind']);
                    // The ledger stays outside structural comparison, but its table and
                    // every index still occupy the schema's shared relation namespace.
                    $platform['excluded_relations'][$schema.'.'.$name] = ['schema' => $schema, 'name' => $name];
                    foreach ($this->rows(<<<'SQL'
SELECT c.relname AS name
FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
WHERE i.indrelid = CAST(:oid AS oid)
ORDER BY c.relname
SQL, ['oid' => $relation['oid']]) as $index) {
                        $platform['excluded_relations'][$schema.'.'.$index['name']] = ['schema' => $schema, 'name' => $index['name']];
                    }

                    continue;
                }

                if (! in_array($relation['relkind'], ['r', 'p'], true)) {
                    continue;
                }
                $key = $schema.'.'.$name;

                if (isset($tables[$key])) {
                    throw new RuntimeException('Ambiguous schema.table key: '.$key);
                }
                $tableOids[$key] = (string) $relation['oid'];
                $tables[$key] = [
                    'schema' => $schema,
                    'name' => $name,
                    'columns' => [],
                    'constraints' => [],
                    'indexes' => [],
                ];

                if (self::boolean($relation['has_extended_statistics'])) {
                    // DROP COLUMN can silently remove statistics, including those in another schema.
                    $tables[$key]['drop_column_blockers'] = ['extended_statistics'];
                }
            }
            foreach (array_chunk($tableOids, 100, true) as $group) {
                $oids = array_values($group);
                $columns = $this->columns($oids);
                $indexes = $this->indexes($oids);
                $constraints = $this->constraints($oids, $indexes['backing']);
                foreach ($group as $key => $oid) {
                    $tables[$key]['columns'] = $columns[$oid] ?? [];
                    $tables[$key]['constraints'] = $constraints[$oid] ?? [];
                    $tables[$key]['indexes'] = $indexes['standalone'][$oid] ?? [];
                }
                unset($columns, $constraints, $indexes);
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

    /** @return array<string, mixed> */
    private function platform(int $version): array
    {
        $database = $this->rows(<<<'SQL'
SELECT pg_encoding_to_char(d.encoding) AS encoding, d.datcollate AS collate, d.datctype AS ctype,
       to_jsonb(d)->>'datlocprovider' AS locale_provider,
       COALESCE(to_jsonb(d)->>'datlocale', to_jsonb(d)->>'daticulocale') AS locale,
       to_jsonb(d)->>'datcollversion' AS collation_version,
       pg_database_collation_actual_version(d.oid) AS actual_collation_version
FROM pg_database d WHERE d.datname = current_database()
SQL)[0];
        foreach (['collate', 'ctype'] as $field) {
            $database[$field] = $this->normalizeLocale($database[$field]);
        }

        if (($database['locale_provider'] ?? null) === 'c') {
            $database['locale'] = $this->normalizeLocale($database['locale']);
        }
        $settings = [];
        foreach ($this->rows(<<<'SQL'
SELECT name, setting FROM pg_settings WHERE name IN
('server_encoding', 'client_encoding', 'lc_collate', 'lc_ctype', 'TimeZone', 'DateStyle',
 'IntervalStyle', 'standard_conforming_strings', 'default_table_access_method',
 'default_tablespace', 'default_toast_compression', 'block_size', 'integer_datetimes') ORDER BY name
SQL) as $row) {
            $settings[(string) $row['name']] = in_array($row['name'], ['lc_collate', 'lc_ctype'], true)
                ? $this->normalizeLocale($row['setting']) : $row['setting'];
        }
        $extensions = [];
        foreach ($this->rows('SELECT e.extname, e.extversion, n.nspname AS schema FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace ORDER BY e.extname') as $row) {
            $extensions[(string) $row['extname']] = ['version' => $row['extversion'], 'schema' => $row['schema']];
        }
        $collations = [];
        foreach ($this->scoped(<<<'SQL'
SELECT n.nspname, c.collname, c.collprovider, c.collisdeterministic,
       c.collencoding, c.collcollate, c.collctype, c.collversion,
       COALESCE(to_jsonb(c)->>'colllocale', to_jsonb(c)->>'colliculocale') AS locale,
       pg_collation_actual_version(c.oid) AS actual_version
FROM pg_collation c JOIN pg_namespace n ON n.oid = c.collnamespace
WHERE __SCOPE__ OR (n.nspname = 'pg_catalog' AND c.collname IN ('default', 'C', 'POSIX')) OR c.oid IN (
 SELECT a.attcollation FROM pg_attribute a JOIN pg_class t ON t.oid = a.attrelid
 JOIN pg_namespace ns ON ns.oid = t.relnamespace
 WHERE ns.nspname IN (SELECT jsonb_array_elements_text(CAST(:collation_schemas AS jsonb))) AND a.attnum > 0 AND NOT a.attisdropped
UNION
 SELECT unnest(i.indcollation::oid[]) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
 JOIN pg_namespace ns ON ns.oid = t.relnamespace
 WHERE ns.nspname IN (SELECT jsonb_array_elements_text(CAST(:index_collation_schemas AS jsonb)))
UNION
 SELECT d.refobjid FROM pg_depend d
 WHERE d.refclassid = 'pg_collation'::regclass AND (
   (d.classid = 'pg_constraint'::regclass AND d.objid IN (
      SELECT con.oid FROM pg_constraint con JOIN pg_class t ON t.oid = con.conrelid
      JOIN pg_namespace ns ON ns.oid = t.relnamespace
      WHERE ns.nspname IN (SELECT jsonb_array_elements_text(CAST(:constraint_collation_schemas AS jsonb)))))
   OR (d.classid = 'pg_attrdef'::regclass AND d.objid IN (
      SELECT ad.oid FROM pg_attrdef ad JOIN pg_class t ON t.oid = ad.adrelid
      JOIN pg_namespace ns ON ns.oid = t.relnamespace
      WHERE ns.nspname IN (SELECT jsonb_array_elements_text(CAST(:expression_collation_schemas AS jsonb)))))
   OR (d.classid = 'pg_class'::regclass AND d.objid IN (
      SELECT i.indexrelid FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
      JOIN pg_namespace ns ON ns.oid = t.relnamespace
      WHERE ns.nspname IN (SELECT jsonb_array_elements_text(CAST(:dependency_collation_schemas AS jsonb)))))
 )
) ORDER BY n.nspname, c.collname
SQL, array_fill_keys(['index_collation_schemas', 'collation_schemas', 'constraint_collation_schemas', 'expression_collation_schemas', 'dependency_collation_schemas'], Snapshot::canonical($this->schemas))) as $row) {
            if ($row['collprovider'] === 'c') {
                foreach (['collcollate', 'collctype', 'locale'] as $field) {
                    $row[$field] = $this->normalizeLocale($row[$field]);
                }
            }
            $collations[(string) $row['nspname'].'.'.(string) $row['collname']] = $row;
        }

        return ['server_major' => intdiv($version, 10000), 'server_version_num' => $version,
            'server_version' => $this->rows("SELECT current_setting('server_version') AS version")[0]['version'],
            'schemas' => $this->schemas, 'database' => $database, 'settings' => $settings,
            'extensions' => $extensions, 'collations' => $collations];
    }

    private function normalizeLocale(mixed $locale): mixed
    {
        // Only encoding spelling is interchangeable; retain region, case and modifiers.
        return is_string($locale) ? preg_replace('/\.utf-?8(?=@|\z)/i', '.utf8', $locale) : $locale;
    }

    /** @param list<string> $oids
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function columns(array $oids): array
    {
        $columns = [];
        foreach ($this->rows(<<<'SQL'
SELECT a.attrelid::text AS table_oid, a.attname, format_type(a.atttypid, a.atttypmod) AS type,
       NOT a.attnotnull AS nullable, pg_get_expr(d.adbin, d.adrelid, false) AS default,
       a.attidentity AS identity, a.attgenerated AS generated,
       CASE WHEN co.oid IS NOT NULL THEN quote_ident(n.nspname)||'.'||quote_ident(co.collname) END AS collation
FROM pg_attribute a
LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
LEFT JOIN pg_collation co ON co.oid = a.attcollation
LEFT JOIN pg_namespace n ON n.oid = co.collnamespace
WHERE a.attrelid IN (SELECT value::oid FROM jsonb_array_elements_text(CAST(:oids AS jsonb)))
  AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attrelid, a.attnum
SQL, ['oids' => Snapshot::canonical($oids)]) as $column) {
            $oid = (string) $column['table_oid'];
            $name = (string) $column['attname'];
            unset($column['table_oid'], $column['attname']);
            $column['nullable'] = self::boolean($column['nullable']);
            $columns[$oid][$name] = $column;
        }

        return $columns;
    }

    /** @param list<string> $oids
     * @param  array<string, array<string, mixed>>  $backingIndexes
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function constraints(array $oids, array $backingIndexes): array
    {
        $constraints = [];
        foreach ($this->rows(<<<'SQL'
SELECT c.conrelid::text AS table_oid, c.conname, c.contype AS kind, pg_get_constraintdef(c.oid, false) AS definition,
       c.condeferrable AS deferrable, c.condeferred AS initially_deferred, c.convalidated AS validated,
       c.connoinherit AS no_inherit, rn.nspname AS foreign_schema, r.relname AS foreign_table,
       c.confupdtype AS on_update, c.confdeltype AS on_delete, c.confmatchtype AS match,
       CASE WHEN c.contype IN ('p', 'u') THEN i.relname END AS backing_index,
       CASE WHEN c.contype IN ('p', 'u') THEN c.conindid::text END AS index_oid,
       COALESCE((SELECT jsonb_agg(a.attname ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(num, ord)
                 JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.num), '[]') AS columns,
       COALESCE((SELECT jsonb_agg(a.attname ORDER BY k.ord) FROM unnest(c.confkey) WITH ORDINALITY k(num, ord)
                 JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.num), '[]') AS foreign_columns,
       COALESCE((SELECT jsonb_agg(a.attname ORDER BY k.ord) FROM unnest(c.confdelsetcols) WITH ORDINALITY k(num, ord)
                 JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.num), '[]') AS delete_set_columns
FROM pg_constraint c LEFT JOIN pg_class i ON i.oid = c.conindid
LEFT JOIN pg_class r ON r.oid = c.confrelid LEFT JOIN pg_namespace rn ON rn.oid = r.relnamespace
WHERE c.conrelid IN (SELECT value::oid FROM jsonb_array_elements_text(CAST(:oids AS jsonb)))
  AND c.contype IN ('p', 'u', 'f', 'c', 'n') ORDER BY c.conrelid, c.conname
SQL, ['oids' => Snapshot::canonical($oids)]) as $constraint) {
            $oid = (string) $constraint['table_oid'];
            $name = (string) $constraint['conname'];
            $indexOid = $constraint['index_oid'];
            unset($constraint['table_oid'], $constraint['conname'], $constraint['index_oid']);
            foreach (['deferrable', 'initially_deferred', 'validated', 'no_inherit'] as $key) {
                $constraint[$key] = self::boolean($constraint[$key]);
            }
            foreach (['columns', 'foreign_columns', 'delete_set_columns'] as $key) {
                $constraint[$key] = json_decode((string) $constraint[$key], true, 512, JSON_THROW_ON_ERROR);
            }

            if ($indexOid !== null) {
                if (! isset($backingIndexes[(string) $indexOid])) {
                    throw new RuntimeException('Constraint backing index metadata is missing.');
                }
                $constraint['index'] = $backingIndexes[(string) $indexOid];
            }
            $semantic = $constraint;
            unset($semantic['backing_index']);
            $constraint['signature'] = Snapshot::canonical($semantic);
            $constraints[$oid][$name] = $constraint;
        }

        return $constraints;
    }

    /** @param list<string> $oids
     * @return array{standalone: array<string, array<string, array<string, mixed>>>, backing: array<string, array<string, mixed>>}
     */
    private function indexes(array $oids): array
    {
        $indexes = [];
        $backing = [];
        foreach ($this->indexRows($oids) as $row) {
            $oid = (string) $row['table_oid'];
            $indexOid = (string) $row['index_oid'];
            $constraintOwned = self::boolean($row['constraint_owned']);
            unset($row['table_oid'], $row['index_oid'], $row['constraint_owned']);
            $index = $this->indexSemantic($row);

            if ($constraintOwned) {
                $backing[$indexOid] = $index;

                continue;
            }
            $index['signature'] = Snapshot::canonical($index);
            $index['definition'] = $row['definition'];
            $indexes[$oid][(string) $row['name']] = $index;
        }

        return ['standalone' => $indexes, 'backing' => $backing];
    }

    /** @param list<string> $oids
     * @return list<array<string, mixed>>
     */
    private function indexRows(array $oids): array
    {
        return $this->rows(<<<'SQL'
SELECT i.indrelid::text AS table_oid, i.indexrelid::text AS index_oid,
       EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = i.indexrelid AND c.contype IN ('p', 'u', 'x')) AS constraint_owned,
       ic.relname AS name, pg_get_indexdef(i.indexrelid, 0, false) AS definition,
       am.amname AS method, i.indisunique AS unique, i.indnullsnotdistinct AS nulls_not_distinct,
       pg_get_expr(i.indpred, i.indrelid, false) AS predicate,
       COALESCE((SELECT jsonb_agg(jsonb_build_object(
           'definition', pg_get_indexdef(i.indexrelid, k.pos, false),
           'column', a.attname, 'opclass', quote_ident(onsp.nspname)||'.'||quote_ident(opc.opcname),
           'collation', CASE WHEN col.oid IS NOT NULL THEN quote_ident(cn.nspname)||'.'||quote_ident(col.collname) END,
           'options', i.indoption[k.pos - 1]::integer,
           'order', CASE WHEN am.amname = 'btree' THEN CASE WHEN (i.indoption[k.pos - 1] & 1) = 1 THEN 'DESC' ELSE 'ASC' END END,
           'nulls', CASE WHEN am.amname = 'btree' THEN CASE WHEN (i.indoption[k.pos - 1] & 2) = 2 THEN 'FIRST' ELSE 'LAST' END END
       ) ORDER BY k.pos) FROM generate_series(1, i.indnkeyatts) k(pos)
       LEFT JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[k.pos - 1]
       LEFT JOIN pg_opclass opc ON opc.oid = i.indclass[k.pos - 1]
       LEFT JOIN pg_namespace onsp ON onsp.oid = opc.opcnamespace
       LEFT JOIN pg_collation col ON col.oid = i.indcollation[k.pos - 1]
       LEFT JOIN pg_namespace cn ON cn.oid = col.collnamespace), '[]') AS keys,
       COALESCE((SELECT jsonb_agg(a.attname ORDER BY k.pos)
         FROM generate_series(i.indnkeyatts + 1, i.indnatts) k(pos)
         JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[k.pos - 1]), '[]') AS include
FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid JOIN pg_am am ON am.oid = ic.relam
WHERE i.indrelid IN (SELECT value::oid FROM jsonb_array_elements_text(CAST(:oids AS jsonb)))
ORDER BY i.indrelid, ic.relname
SQL, ['oids' => Snapshot::canonical($oids)]);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function indexSemantic(array $row): array
    {
        unset($row['name'], $row['definition']);
        foreach (['unique', 'nulls_not_distinct'] as $key) {
            $row[$key] = self::boolean($row[$key]);
        }
        foreach (['keys', 'include'] as $key) {
            $row[$key] = json_decode((string) $row[$key], true, 512, JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /** @return list<string> */
    private function migrationNames(string $schema, string $table, string $kind): array
    {
        if ($kind !== 'r') {
            throw new RuntimeException('Migration ledger must be an ordinary table.');
        }
        $columns = $this->rows(<<<'SQL'
SELECT a.attname, t.typname, a.attnotnull
FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_type t ON t.oid = a.atttypid
WHERE n.nspname = :schema AND c.relname = :table AND a.attnum > 0 AND NOT a.attisdropped
SQL, ['schema' => $schema, 'table' => $table]);
        $required = ['id' => ['int2', 'int4', 'int8'], 'migration' => ['varchar', 'text'], 'batch' => ['int2', 'int4', 'int8']];
        foreach ($columns as $column) {
            $name = (string) $column['attname'];

            if (isset($required[$name])) {
                if (! in_array($column['typname'], $required[$name], true) || ! self::boolean($column['attnotnull'])) {
                    throw new RuntimeException('Malformed migration ledger column: '.$name);
                }
                unset($required[$name]);
            }
        }

        if ($required !== []) {
            throw new RuntimeException('Migration ledger is missing required columns.');
        }
        $qualified = self::quote($schema).'.'.self::quote($table);
        // Missing columns and inaccessible/malformed ledgers are real errors, never an empty ledger.
        $rows = $this->rows('SELECT id, migration, batch FROM '.$qualified.' ORDER BY id');
        $names = [];
        $seenNames = [];
        $ids = [];
        foreach ($rows as $row) {
            if (! is_string($row['migration']) || $row['migration'] === ''
                || ! ctype_digit((string) $row['id']) || (int) $row['id'] < 1
                || ! ctype_digit((string) $row['batch']) || (int) $row['batch'] < 1
                || isset($ids[(string) $row['id']]) || isset($seenNames['migration:'.$row['migration']])) {
                throw new RuntimeException('Malformed migration ledger.');
            }
            $ids[(string) $row['id']] = true;
            $seenNames['migration:'.$row['migration']] = true;
            $names[] = $row['migration'];
        }

        return $names;
    }

    /** @return list<array<string, mixed>> */
    private function unsupported(): array
    {
        $issues = $this->rows(<<<'SQL'
SELECT e.evtname AS object, 'database_event_trigger' AS reason,
       jsonb_build_object('event', e.evtevent, 'enabled', e.evtenabled,
                         'function', e.evtfoid::regprocedure::text) AS details
FROM pg_event_trigger e WHERE e.evtenabled <> 'D' ORDER BY e.evtname
SQL);
        foreach ($issues as &$issue) {
            $issue['details'] = json_decode((string) $issue['details'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($issue);
        foreach ($this->schemas as $schema) {
            if ($this->rows('SELECT 1 FROM pg_namespace WHERE nspname = :name', ['name' => $schema]) === []) {
                $issues[] = ['object' => $schema, 'reason' => 'missing_schema'];
            }
        }
        // Each query emits catalog evidence. New unsupported classes can be added without hiding SQL errors.
        $queries = [
            <<<'SQL'
SELECT n.nspname||'.'||c.relname AS object, 'relation_properties' AS reason,
       jsonb_build_object('kind', c.relkind, 'persistence', c.relpersistence, 'partition', c.relispartition,
       'row_security', c.relrowsecurity, 'force_row_security', c.relforcerowsecurity,
       'tablespace', c.reltablespace, 'options', c.reloptions, 'replica_identity', c.relreplident,
       'access_method', am.amname) AS details
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace LEFT JOIN pg_am am ON am.oid = c.relam
WHERE __SCOPE__ AND c.relkind IN ('r', 'p', 'v', 'm', 'f') AND
(c.relkind <> 'r' OR c.relpersistence <> 'p' OR c.relispartition OR c.relrowsecurity OR c.relforcerowsecurity
 OR c.reltablespace <> 0 OR c.reloptions IS NOT NULL OR c.relreplident <> 'd' OR (c.relkind = 'r' AND am.amname <> 'heap')
 OR EXISTS (SELECT 1 FROM pg_inherits h WHERE h.inhrelid = c.oid OR h.inhparent = c.oid))
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.relname||'.'||t.tgname AS object, 'trigger' AS reason, NULL::jsonb AS details
FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE __SCOPE__ AND NOT t.tgisinternal
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.relname||'.'||r.rulename AS object, 'rule' AS reason, NULL::jsonb AS details
FROM pg_rewrite r JOIN pg_class c ON c.oid = r.ev_class JOIN pg_namespace n ON n.oid = c.relnamespace WHERE __SCOPE__
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.relname||'.'||p.polname AS object, 'policy' AS reason, NULL::jsonb AS details
FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE __SCOPE__
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||t.typname AS object, 'custom_type' AS reason, NULL::jsonb AS details
FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace LEFT JOIN pg_class c ON c.oid = t.typrelid
WHERE __SCOPE__ AND NOT (t.typelem <> 0 AND t.typlen = -1)
 AND NOT (t.typtype = 'c' AND c.relkind IN ('r', 'p', 'v', 'm', 'f'))
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||p.proname||'('||pg_get_function_identity_arguments(p.oid)||')' AS object,
       'function' AS reason, NULL::jsonb AS details
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE __SCOPE__
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.relname AS object, 'sequence' AS reason, to_jsonb(s) - 'seqrelid' AS details
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_sequence s ON s.seqrelid = c.oid
WHERE __SCOPE__ AND (NOT EXISTS (SELECT 1 FROM pg_depend d JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
 WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')
 AND (s.seqtypid = a.atttypid OR (d.deptype = 'a' AND a.attidentity = ''
      AND s.seqtypid = 'int8'::regtype AND a.atttypid IN ('int2'::regtype, 'int4'::regtype)))
 -- Legacy serial sequences can retain bigint storage with the owning column's standard bounds.
 AND s.seqmax = CASE a.atttypid WHEN 'int2'::regtype THEN 32767 WHEN 'int4'::regtype THEN 2147483647 ELSE 9223372036854775807 END
 AND (a.attidentity <> '' OR EXISTS (SELECT 1 FROM pg_attrdef ad JOIN pg_depend dd ON dd.classid = 'pg_attrdef'::regclass AND dd.objid = ad.oid
 AND dd.refclassid = 'pg_class'::regclass AND dd.refobjid = c.oid WHERE ad.adrelid = a.attrelid AND ad.adnum = a.attnum)))
 OR c.relpersistence <> 'p' OR c.reloptions IS NOT NULL OR c.reltablespace <> 0
 OR s.seqstart <> 1 OR s.seqincrement <> 1 OR s.seqmin <> 1 OR s.seqcache <> 1 OR s.seqcycle)
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.relname||'.'||a.attname AS object, 'unowned_or_external_sequence_default' AS reason,
       jsonb_build_object('sequence_schema', sn.nspname, 'sequence', seq.relname) AS details
FROM pg_attrdef ad JOIN pg_class c ON c.oid = ad.adrelid JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ad.adnum
JOIN pg_depend d ON d.classid = 'pg_attrdef'::regclass AND d.objid = ad.oid AND d.refclassid = 'pg_class'::regclass
JOIN pg_class seq ON seq.oid = d.refobjid AND seq.relkind = 'S' JOIN pg_namespace sn ON sn.oid = seq.relnamespace
WHERE __SCOPE__ AND (sn.nspname <> n.nspname OR NOT EXISTS (
 SELECT 1 FROM pg_depend own WHERE own.classid = 'pg_class'::regclass AND own.objid = seq.oid
 AND own.refclassid = 'pg_class'::regclass AND own.refobjid = c.oid AND own.refobjsubid = a.attnum AND own.deptype IN ('a', 'i')))
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||ic.relname AS object, 'index_properties' AS reason,
       jsonb_build_object('valid', i.indisvalid, 'ready', i.indisready, 'live', i.indislive,
       'clustered', i.indisclustered, 'replica_identity', i.indisreplident, 'tablespace', ic.reltablespace, 'options', ic.reloptions) AS details
FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_class ic ON ic.oid = i.indexrelid
WHERE __SCOPE__ AND (NOT i.indisvalid OR NOT i.indisready OR NOT i.indislive OR i.indisclustered OR i.indisreplident
 OR ic.reltablespace <> 0 OR ic.reloptions IS NOT NULL)
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||t.relname||'.'||c.conname AS object, 'constraint_properties' AS reason,
       jsonb_build_object('kind', c.contype, 'definition', pg_get_constraintdef(c.oid, false)) AS details
FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace
WHERE __SCOPE__ AND (c.contype NOT IN ('p', 'u', 'f', 'c', 'n') OR c.conparentid <> 0
 OR to_jsonb(c)->>'conenforced' = 'false' OR to_jsonb(c)->>'conperiod' = 'true')
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.relname||'.'||a.attname AS object, 'column_properties' AS reason,
       jsonb_build_object('type_schema', tn.nspname, 'options', a.attoptions, 'fdw_options', a.attfdwoptions,
       'storage', a.attstorage, 'compression', a.attcompression, 'statistics', to_jsonb(a)->>'attstattarget') AS details
FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_type t ON t.oid = a.atttypid JOIN pg_namespace tn ON tn.oid = t.typnamespace
WHERE __SCOPE__ AND c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped
AND (tn.nspname <> 'pg_catalog' OR a.attoptions IS NOT NULL OR a.attfdwoptions IS NOT NULL
 OR a.attstorage <> t.typstorage OR a.attcompression <> '' OR COALESCE((to_jsonb(a)->>'attstattarget')::int, -1) <> -1)
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.relname AS object, 'extension_relation' AS reason, NULL::jsonb AS details
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_depend d ON d.classid = 'pg_class'::regclass
AND d.objid = c.oid AND d.deptype = 'e' WHERE __SCOPE__
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||e.extname AS object, 'extension' AS reason, NULL::jsonb AS details
FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE __SCOPE__
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||t.relname||'.'||c.conname AS object, 'foreign_key_outside_scope' AS reason,
       jsonb_build_object('referenced_schema', rn.nspname, 'referenced_table', r.relname) AS details
FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace
JOIN pg_class r ON r.oid = c.confrelid JOIN pg_namespace rn ON rn.oid = r.relnamespace
WHERE __SCOPE__ AND c.contype = 'f'
AND rn.nspname NOT IN (SELECT jsonb_array_elements_text(CAST(:foreign_schemas AS jsonb)))
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||r.relname AS object, 'foreign_key_from_outside_scope' AS reason,
       jsonb_build_object('source_schema', sn.nspname, 'source_table', t.relname, 'constraint', c.conname) AS details
FROM pg_constraint c JOIN pg_class r ON r.oid = c.confrelid JOIN pg_namespace n ON n.oid = r.relnamespace
JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace sn ON sn.oid = t.relnamespace
WHERE __SCOPE__ AND c.contype = 'f'
AND sn.nspname NOT IN (SELECT jsonb_array_elements_text(CAST(:foreign_schemas AS jsonb)))
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||t.relname AS object, 'dependent_custom_function' AS reason,
       jsonb_build_object('function_schema', pn.nspname, 'function', p.proname) AS details
FROM pg_class t JOIN pg_namespace n ON n.oid = t.relnamespace
JOIN pg_depend d ON (d.classid = 'pg_class'::regclass AND (d.objid = t.oid OR d.objid IN (SELECT indexrelid FROM pg_index WHERE indrelid = t.oid)))
 OR (d.classid = 'pg_attrdef'::regclass AND d.objid IN (SELECT oid FROM pg_attrdef WHERE adrelid = t.oid))
 OR (d.classid = 'pg_constraint'::regclass AND d.objid IN (SELECT oid FROM pg_constraint WHERE conrelid = t.oid))
JOIN pg_proc p ON d.refclassid = 'pg_proc'::regclass AND d.refobjid = p.oid
JOIN pg_namespace pn ON pn.oid = p.pronamespace
WHERE __SCOPE__ AND pn.nspname <> 'pg_catalog'
SQL,
            <<<'SQL'
SELECT n.nspname||'.'||c.collname AS object, 'custom_collation' AS reason, NULL::jsonb AS details
FROM pg_collation c JOIN pg_namespace n ON n.oid = c.collnamespace WHERE __SCOPE__
SQL,
        ];
        foreach ($queries as $sql) {
            foreach ($this->scoped($sql, str_contains($sql, ':foreign_schemas') ? ['foreign_schemas' => Snapshot::canonical($this->schemas)] : []) as $row) {
                if (is_string($row['details'])) {
                    $row['details'] = json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR);
                }
                $issues[] = $row;
            }
        }
        usort($issues, fn (array $a, array $b): int => strcmp(Snapshot::canonical($a), Snapshot::canonical($b)));

        return $issues;
    }

    /** @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function scoped(string $sql, array $parameters = []): array
    {
        $sql = str_replace('__SCOPE__', 'n.nspname IN (SELECT jsonb_array_elements_text(CAST(:schemas AS jsonb)))', $sql);

        return $this->rows($sql, ['schemas' => Snapshot::canonical($this->schemas), ...$parameters]);
    }

    /** @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
