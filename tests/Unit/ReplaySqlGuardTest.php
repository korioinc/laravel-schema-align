<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Replay\ReplaySqlGuard;

it('allows PostgreSQL column qualifiers matching the original schema', function (string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['c', 'n'], ['align-1234-abcde'], 'pgsql');
    expect(true)->toBeTrue();
})->with([
    'Laravel table existence' => "select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace)",
    'explicit aliases' => 'SELECT c.relname, n.nspname FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace',
    'column aliases' => 'SELECT c.relname AS name, n.nspname AS schema FROM pg_class c, pg_namespace n',
    'quoted aliases' => 'SELECT "c"."relname" FROM pg_class AS "c"',
    'unquoted case folding' => 'SELECT C.relname FROM pg_class C',
    'star' => 'SELECT c.* FROM pg_class c',
    'nested expression' => 'SELECT coalesce(c.relname, n.nspname) FROM pg_class c, pg_namespace n',
    'subquery' => 'SELECT c.relname FROM (SELECT relname FROM pg_class) c',
    'group and order' => 'SELECT c.relname FROM pg_class c GROUP BY c.relname HAVING count(*) > 0 ORDER BY c.relname',
    'CTE' => 'WITH c AS (SELECT relname FROM pg_class) SELECT c.relname FROM c',
]);

it('rejects original PostgreSQL schema references even when an alias shares the name', function (string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['c', 'n'], ['align-1234-abcde'], 'pgsql'))
        ->toThrow(RuntimeException::class, 'original namespace');
})->with([
    'relation' => 'SELECT * FROM c.records c',
    'comma relation' => 'SELECT c.relname FROM pg_class c, n.records',
    'comma after join predicate' => 'SELECT * FROM pg_class c JOIN pg_class n ON true, c.records',
    'comma after nested join predicate' => 'SELECT * FROM pg_class c JOIN pg_class n ON (true), c.records',
    'joined relation' => 'SELECT c.relname FROM pg_class c JOIN n.records n ON true',
    'parenthesized relation' => 'SELECT c.relname FROM pg_class c JOIN (n.records n JOIN pg_class p ON true) ON true',
    'only relation' => 'SELECT * FROM ONLY (c.records) c',
    'table query' => 'WITH c AS (SELECT relname FROM pg_class) TABLE c.records',
    'schema table column' => 'SELECT c.records.id FROM pg_class c',
    'function' => 'SELECT c.some_function() FROM pg_class c',
    'cast' => 'SELECT c.relname::c.some_type FROM pg_class c',
    'cast expression' => 'SELECT CAST(c.relname AS c.some_type) FROM pg_class c',
    'typed literal' => "SELECT c.some_type 'value' FROM pg_class c",
    'collation' => 'SELECT c.relname COLLATE c.some_collation FROM pg_class c',
    'type predicate' => 'SELECT c.relname IS OF (c.some_type) FROM pg_class c',
    'type predicate list' => 'SELECT c.relname IS OF (text, c.some_type) FROM pg_class c',
    'JSON return type' => "SELECT json_value('{}', '$' RETURNING c.some_type) FROM pg_class c",
    'nested original relation' => 'SELECT c.relname FROM pg_class c WHERE EXISTS (SELECT 1 FROM c.records)',
    'batch' => 'SELECT c.relname FROM pg_class c; SELECT * FROM c.records',
    'DDL' => 'CREATE TABLE c.records (id integer)',
]);

it('limits every replay DML target including writable CTEs to owned namespaces', function (string $driver, string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], $driver))
        ->toThrow(RuntimeException::class);
})->with(['pgsql', 'mysql'])->with([
    'update' => "UPDATE other_schema.records SET note = 'changed'",
    'insert' => 'INSERT INTO other_schema.records (id) VALUES (1)',
    'delete' => 'DELETE FROM other_schema.records',
    'replace' => 'REPLACE INTO other_schema.records (id) VALUES (1)',
    'quoted target' => 'UPDATE "other_schema"."records" SET id = 1',
    'backtick target' => 'UPDATE `other_schema`.`records` SET id = 1',
    'comments and batch' => 'SELECT 1; UPDATE other_schema /* target */ .records SET id = 1',
    'cte main update' => 'WITH source AS (SELECT 1) UPDATE other_schema.records SET id = 1',
    'cte main insert' => 'WITH source AS (SELECT 1) INSERT INTO other_schema.records VALUES (1)',
    'cte delete' => 'WITH removed AS (DELETE FROM other_schema.records RETURNING id) SELECT * FROM removed',
    'sibling cte write' => 'WITH source AS (SELECT 1), changed AS (UPDATE other_schema.records SET id = 1 RETURNING id) SELECT * FROM changed',
    'nested cte' => 'WITH source AS (WITH changed AS (INSERT INTO other_schema.records VALUES (1) RETURNING id) SELECT * FROM changed) SELECT * FROM source',
]);

it('rejects unqualified PostgreSQL system catalog writes resolved ahead of the search path', function (string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'pgsql'))
        ->toThrow(RuntimeException::class, 'replay DML');
})->with([
    "UPDATE pg_description SET description = 'Changed'",
    'DELETE FROM "pg_description"',
    'INSERT INTO PG_DESCRIPTION SELECT * FROM records',
    "WITH changed AS (UPDATE pg_description SET description = 'Changed' RETURNING objoid) SELECT * FROM changed",
    'CREATE TEMP TABLE probe AS WITH changed AS (UPDATE pg_description SET description = description RETURNING description) SELECT * FROM changed',
]);

it('rejects unqualified PostgreSQL system relations in DDL target positions', function (string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'pgsql'))
        ->toThrow(RuntimeException::class, 'replay DDL');
})->with([
    'table comment' => "COMMENT ON TABLE pg_class IS 'changed'",
    'quoted table comment' => 'COMMENT ON TABLE "pg_class" IS NULL',
    'folded table comment' => "COMMENT ON TABLE PG_CLASS IS 'changed'",
    'column comment' => "COMMENT ON COLUMN pg_class.relname IS 'changed'",
    'index comment' => "COMMENT ON INDEX pg_class_oid_index IS 'changed'",
    'constraint comment' => "COMMENT ON CONSTRAINT pg_class_relname_nsp_index ON pg_class IS 'changed'",
    'table alteration' => 'ALTER TABLE IF EXISTS ONLY pg_class ADD COLUMN note text',
    'parenthesized only' => 'ALTER TABLE ONLY (pg_class) ADD COLUMN note text',
    'index alteration' => 'ALTER INDEX IF EXISTS pg_class_oid_index RENAME TO changed',
    'sequence alteration' => 'ALTER SEQUENCE IF EXISTS pg_sequence RESTART WITH 1',
    'drop table list' => 'DROP TABLE IF EXISTS sample_links, pg_class',
    'drop index list' => 'DROP INDEX CONCURRENTLY IF EXISTS sample_link_id, pg_class_oid_index',
    'drop sequence list' => 'DROP SEQUENCE IF EXISTS sample_link_ids, pg_sequence',
    'truncate first' => 'TRUNCATE ONLY pg_class',
    'truncate list' => 'TRUNCATE TABLE sample_links *, ONLY pg_class RESTART IDENTITY',
    'index table' => 'CREATE INDEX catalog_lookup ON pg_class (oid)',
    'index modifiers' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS catalog_lookup ON ONLY pg_class (oid)',
    'unnamed index' => 'CREATE INDEX ON pg_class (oid)',
    'create partition' => 'CREATE TABLE child PARTITION OF pg_class DEFAULT',
    'create inheritance list' => 'CREATE TABLE child (id integer) INHERITS (sample_links, pg_class)',
    'create foreign key' => 'CREATE TABLE child (id oid REFERENCES pg_class (oid))',
    'alter foreign key' => 'ALTER TABLE sample_links ADD FOREIGN KEY (id) REFERENCES pg_class (oid)',
    'alter inheritance' => 'ALTER TABLE sample_links INHERIT pg_class',
    'remove inheritance' => 'ALTER TABLE sample_links NO INHERIT pg_class',
    'attach partition' => 'ALTER TABLE sample_links ATTACH PARTITION pg_class DEFAULT',
    'detach partition' => 'ALTER TABLE sample_links DETACH PARTITION pg_class',
    'attach index partition' => 'ALTER INDEX sample_link_id ATTACH PARTITION pg_class_oid_index',
    'sequence owner' => 'ALTER SEQUENCE sample_link_ids OWNED BY pg_class.oid',
    'create sequence owner' => 'CREATE SEQUENCE sample_link_ids OWNED BY pg_class.oid',
    'comments and batch' => "SELECT 1; COMMENT /* target */ ON TABLE /* catalog */ pg_class IS 'changed'",
]);

it('permits owned PostgreSQL DDL targets and ordinary pg prefixed identifiers', function (string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'pgsql');
    expect(true)->toBeTrue();
})->with([
    'qualified table comment' => 'COMMENT ON TABLE "align-1234-abcde".pg_class IS NULL',
    'qualified column comment' => 'COMMENT ON COLUMN "align-1234-abcde".pg_class.pg_column IS NULL',
    'column name' => 'COMMENT ON COLUMN sample_links.pg_column IS NULL',
    'constraint name' => 'COMMENT ON CONSTRAINT pg_constraint ON sample_links IS NULL',
    'qualified constraint table' => 'COMMENT ON CONSTRAINT pg_constraint ON "align-1234-abcde".pg_class IS NULL',
    'qualified index comment' => 'COMMENT ON INDEX "align-1234-abcde".pg_index IS NULL',
    'qualified alter only' => 'ALTER TABLE ONLY "align-1234-abcde".pg_class ADD COLUMN pg_column text',
    'qualified drop list' => 'DROP TABLE sample_links, "align-1234-abcde".pg_class',
    'qualified truncate list' => 'TRUNCATE sample_links, ONLY "align-1234-abcde".pg_class',
    'declared index name' => 'CREATE INDEX pg_index ON sample_links (pg_column)',
    'index expression function' => 'CREATE INDEX pg_index ON sample_links ((pg_column_size(pg_column)))',
    'qualified index table' => 'CREATE INDEX pg_index ON ONLY "align-1234-abcde".pg_class (pg_column)',
    'column alteration' => 'ALTER TABLE sample_links ALTER COLUMN pg_column SET DEFAULT pg_backend_pid()',
    'constraint alteration' => 'ALTER TABLE sample_links DROP CONSTRAINT pg_constraint',
    'existing local constraint index' => 'ALTER TABLE sample_links ADD CONSTRAINT pg_constraint PRIMARY KEY USING INDEX pg_index',
    'local replica identity index' => 'ALTER TABLE sample_links REPLICA IDENTITY USING INDEX pg_index',
    'column and constraint declarations' => 'CREATE TABLE sample_links (pg_column integer, CONSTRAINT pg_constraint CHECK (pg_column > 0))',
    'owned secondary relation' => 'ALTER TABLE sample_links ATTACH PARTITION "align-1234-abcde".pg_class DEFAULT',
    'owned inheritance list' => 'CREATE TABLE child (id integer) INHERITS (sample_links, "align-1234-abcde".pg_class)',
    'owned foreign key' => 'ALTER TABLE sample_links ADD FOREIGN KEY (pg_column) REFERENCES "align-1234-abcde".pg_class (oid)',
    'owned sequence dependency' => 'ALTER SEQUENCE sample_link_ids OWNED BY "align-1234-abcde".pg_class.pg_column',
    'sequence owner removal' => 'ALTER SEQUENCE sample_link_ids OWNED BY NONE',
    'non inherited constraint' => 'ALTER TABLE sample_links ADD CONSTRAINT pg_constraint CHECK (pg_column > 0) NO INHERIT',
    'read catalog function' => 'CREATE TABLE sample_links AS SELECT pg_backend_pid() AS pg_column',
]);

it('rejects DML target forms whose ownership cannot be established', function (string $driver, string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], $driver))
        ->toThrow(RuntimeException::class);
})->with([
    ['pgsql', 'UPDATE ONLY (other_schema.records) SET id = 1'],
    ['pgsql', 'DELETE FROM ONLY other_schema.records'],
    ['pgsql', 'WITH source AS (SELECT 1) MERGE INTO other_schema.records USING source ON true WHEN MATCHED THEN DELETE'],
    ['mysql', 'UPDATE LOW_PRIORITY IGNORE other_schema.records SET id = 1'],
    ['mysql', 'INSERT LOW_PRIORITY IGNORE INTO other_schema.records VALUES (1)'],
    ['mysql', 'REPLACE DELAYED other_schema.records VALUES (1)'],
    ['mysql', 'DELETE LOW_PRIORITY QUICK IGNORE FROM other_schema.records'],
    ['mysql', 'UPDATE records r JOIN other_schema.records o ON r.id = o.id SET o.id = 2'],
    ['mysql', 'UPDATE records r, other_schema.records o SET o.id = 2'],
    ['mysql', 'DELETE o FROM records r JOIN other_schema.records o ON r.id = o.id'],
    ['mysql', 'DELETE FROM o USING other_schema.records o'],
    ['mysql', 'DELETE FROM records, other_schema.records USING records, other_schema.records'],
]);

it('permits single owned DML targets with aliases expressions and read sources', function (string $driver, string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], $driver);
    expect(true)->toBeTrue();
})->with([
    ['pgsql', 'UPDATE ONLY ("align-1234-abcde".records) AS r SET id = r.id + 1'],
    ['pgsql', 'DELETE FROM ONLY "align-1234-abcde".records AS r USING other_schema.records o WHERE r.id = o.id'],
    ['pgsql', 'UPDATE records r SET id = o.id FROM other_schema.records o WHERE r.id = o.id'],
    ['pgsql', 'UPDATE "align-1234-abcde".pg_records SET id = 1'],
    ['pgsql', 'WITH changed AS (UPDATE records SET id = 1 RETURNING id) INSERT INTO records SELECT id FROM changed'],
    ['pgsql', 'INSERT INTO records VALUES (1) ON CONFLICT (id) DO UPDATE SET id = excluded.id'],
    ['pgsql', 'SELECT * FROM records FOR NO KEY UPDATE'],
    ['mysql', 'UPDATE LOW_PRIORITY IGNORE `align-1234-abcde`.records AS r SET r.id = 1'],
    ['mysql', 'DELETE LOW_PRIORITY QUICK IGNORE FROM `align-1234-abcde`.records AS r WHERE r.id = 1 LIMIT 1'],
    ['mysql', 'INSERT IGNORE INTO records SELECT * FROM other_schema.records'],
    ['mysql', 'INSERT INTO records VALUES (1) ON DUPLICATE KEY UPDATE id = VALUES(id)'],
    ['mysql', "REPLACE INTO records (note) VALUES (REPLACE('old', 'old', 'new'))"],
    ['mysql', 'SELECT * FROM records FOR UPDATE'],
]);

it('rejects explicit sequence mutation functions before resolving their targets', function (string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'pgsql'))
        ->toThrow(RuntimeException::class);
})->with([
    "SELECT setval('fixture_scope.migrations_id_seq'::regclass, 1000)",
    "SELECT nextval('other_schema.records_id_seq')",
    'SELECT "pg_catalog"."setval"(?::regclass, 1000)',
    'WITH changed AS (SELECT pg_catalog.nextval(123::regclass)) SELECT * FROM changed',
    "INSERT INTO records VALUES (nextval('other_schema.records_id_seq'))",
    "CREATE TABLE records (id bigint DEFAULT nextval('other_schema.records_id_seq'))",
    "SELECT setval('records_id_seq', 1000)",
    "SELECT nextval('records_' || 'id_seq')",
    'SELECT nextval($$other_schema.records_id_seq$$)',
    "SELECT * FROM LATERAL nextval('other_schema.records_id_seq')",
    "SELECT * FROM sample_links JOIN LATERAL setval('other_schema.records_id_seq', 1000) AS changed ON true",
    "SELECT 1 OPERATOR(pg_catalog.+) nextval('fixture_scope.migrations_id_seq')",
    "SELECT 1 OPERATOR(pg_catalog.+) setval('fixture_scope.migrations_id_seq', 1000)",
    "SELECT OPERATOR(pg_catalog.-) nextval('fixture_scope.migrations_id_seq')",
]);

it('permits sequence function names in inert SQL text and ordinary identifiers', function (string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'pgsql');
    expect(true)->toBeTrue();
})->with([
    "SELECT 'setval(1, 1000)'",
    'SELECT $$nextval(123)$$',
    'SELECT 1 /* nextval(123) */',
    'SELECT "nextval", "setval" FROM records',
    'CREATE TABLE records (id bigserial PRIMARY KEY)',
]);

it('refuses DDL and commands whose targets are not isolated by the replay namespace', function (string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'pgsql'))
        ->toThrow(RuntimeException::class);
})->with([
    'drop publication' => 'DROP PUBLICATION IF EXISTS app_events',
    'create publication' => 'CREATE PUBLICATION app_events FOR ALL TABLES',
    'alter publication' => "ALTER PUBLICATION app_events SET (publish='insert')",
    'subscription' => 'DROP SUBSCRIPTION IF EXISTS app_subscriber',
    'schema comment' => "COMMENT ON SCHEMA fixture_scope IS 'changed'",
    'database comment' => "COMMENT ON DATABASE application IS 'changed'",
    'large object comment' => "COMMENT ON LARGE OBJECT 123 IS 'changed'",
    'bulk table alteration' => 'ALTER TABLE ALL IN TABLESPACE original SET TABLESPACE replacement',
    'bulk index alteration' => 'ALTER INDEX ALL IN TABLESPACE original SET TABLESPACE replacement',
    'security label' => "SECURITY LABEL ON SCHEMA fixture_scope IS 'changed'",
    'database reindex' => 'REINDEX DATABASE application',
    'replication control' => 'START REPLICA',
    'prepared transaction' => "COMMIT PREPARED 'original_transaction'",
    'select into' => 'SELECT 1 AS id INTO public.sample_links',
    'cte select into' => 'WITH source AS (SELECT 1 AS id) SELECT id INTO public.sample_links FROM source',
    'parenthesized select into' => 'WITH source AS (SELECT 1 AS id) (SELECT id INTO public.sample_links FROM source)',
    'cte named update' => 'WITH update AS (SELECT 1 AS id) SELECT id INTO public.sample_links FROM update',
]);

it('permits supported local DDL and ordinary transaction commands', function (string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'pgsql');
    expect(true)->toBeTrue();
})->with([
    'temporary table' => 'CREATE TEMPORARY TABLE scratch (id integer)',
    'unlogged table' => 'CREATE UNLOGGED TABLE scratch (id integer)',
    'unique index' => 'CREATE UNIQUE INDEX sample_link_id ON sample_links (id)',
    'concurrent index' => 'CREATE INDEX CONCURRENTLY sample_link_id ON sample_links (id)',
    'create sequence' => 'CREATE SEQUENCE "align-1234-abcde".sample_link_ids',
    'alter sequence' => 'ALTER SEQUENCE "align-1234-abcde".sample_link_ids RESTART WITH 1',
    'drop sequence' => 'DROP SEQUENCE IF EXISTS "align-1234-abcde".sample_link_ids',
    'index comment' => "COMMENT ON INDEX sample_link_id IS 'lookup'",
    'constraint comment' => "COMMENT ON CONSTRAINT sample_link_pk ON sample_links IS 'primary'",
    'truncate' => 'TRUNCATE ONLY sample_links',
    'rename table' => 'RENAME TABLE sample_links TO archived_sample_links',
    'transaction' => 'START TRANSACTION; SAVEPOINT step; ROLLBACK TO SAVEPOINT step; RELEASE SAVEPOINT step; COMMIT',
    'cte insert' => 'WITH source AS (SELECT 1 AS id) INSERT INTO sample_links (id) SELECT id FROM source',
    'data changing cte' => 'WITH inserted AS (INSERT INTO sample_links (id) VALUES (1) RETURNING id) SELECT id FROM inserted',
    'quoted into column' => 'SELECT "into" FROM sample_links',
    'write keyword labels' => 'SELECT 1 AS update, 2 AS insert, 3 AS delete, 4 AS replace, 5 AS merge',
    'write keyword column' => 'CREATE TABLE records (update integer); INSERT INTO records (update) VALUES (1); SELECT (update) FROM records',
    'cte named update with reference' => 'WITH update AS (SELECT 1 AS id) SELECT * FROM update',
    'cte named merge' => 'WITH merge AS (SELECT 1 AS id) SELECT * FROM merge',
    'sequence alias column list' => 'SELECT * FROM (VALUES (1)) AS nextval(id)',
    'sequence cte column list' => 'WITH setval(id) AS (SELECT 1) SELECT * FROM setval',
    'implicit sequence alias' => 'SELECT * FROM sample_links nextval(id)',
    'sequence name as table' => 'CREATE TABLE nextval (id integer); INSERT INTO nextval (id) VALUES (1)',
    'qualified sequence name as table' => 'CREATE TABLE "align-1234-abcde".setval (id integer); INSERT INTO "align-1234-abcde".setval (id) VALUES (1)',
    'parenthesized read' => 'WITH source AS (SELECT 1 AS id) (SELECT id FROM source)',
    'sibling cte writes' => 'WITH source AS (SELECT 1 AS id), inserted AS (INSERT INTO sample_links SELECT id FROM source RETURNING id) SELECT id FROM inserted',
]);

it('rejects privilege changes and every global assignment in replay statements', function (string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'mysql'))
        ->toThrow(RuntimeException::class);
})->with([
    'grant schema privilege' => 'GRANT USAGE ON SCHEMA fixture_scope TO PUBLIC',
    'revoke schema privilege' => 'REVOKE USAGE ON SCHEMA fixture_scope FROM PUBLIC',
    'global system variable' => "SET @@GLOBAL.sql_mode = ''",
    'quoted global scope' => "SET @@`GLOBAL`.sql_mode = ''",
    'persisted' => "SET PERSIST sql_mode = ''",
    'persisted only' => "SET PERSIST_ONLY sql_mode = ''",
    'qualified persistent' => "SET @@PERSIST.sql_mode = ''",
    'qualified persistent only' => "SET @@PERSIST_ONLY.sql_mode = ''",
    'mixed persistent scope' => "SET @local_value = 1, @@PERSIST.sql_mode = ''",
    'default privileges' => 'ALTER DEFAULT PRIVILEGES IN SCHEMA fixture_scope GRANT SELECT ON TABLES TO PUBLIC',
    'reassign ownership' => 'REASSIGN OWNED BY application TO other_role',
    'default role' => "SET DEFAULT ROLE NONE TO 'application'@'%'",
    'password' => "SET PASSWORD FOR 'application'@'%' = 'changed'",
    'rename user' => "RENAME USER 'application'@'%' TO 'other'@'%'",
    'mixed scopes' => "SET SESSION sql_mode = @@SESSION.sql_mode, @@GLOBAL.sql_mode = ''",
    'global directive after session value' => "SET @local_value = CONCAT('a', 'b'), GLOBAL sql_mode = ''",
    'comments and batch' => "SET @local_value = 1; SET /* scope */ @@ /* system */ GLOBAL.sql_mode = ''",
]);

it('allows session assignments and reads of global settings without changing their scope', function (string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], 'mysql');
    expect(true)->toBeTrue();
})->with([
    'session system variable' => 'SET @@SESSION.sql_mode = @@GLOBAL.sql_mode',
    'local system variable' => "SET @@LOCAL.sql_mode = ''",
    'global read' => 'SET @saved = @@GLOBAL.sql_mode',
    'user variable named global' => 'SET @global = 1, @persist = 2',
    'nested expression' => "SET @saved = CONCAT('SET PERSIST sql_mode', ', GLOBAL'), @other = @@GLOBAL.sql_mode",
    'ordinary session settings' => 'SET SESSION foreign_key_checks = 0, SESSION unique_checks = 0',
]);

it('rejects replay session and global scope changes using decoded identifiers', function (string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['original'], ['align-1234-abcde'], 'pgsql'))
        ->toThrow(RuntimeException::class);
})->with([
    'quoted path' => 'SET "search_path" TO public',
    'local quoted path' => 'SET LOCAL "search_path" TO public',
    'session quoted path' => 'SET SESSION "search_path" = public',
    'mixed case setting' => 'SET LOCAL "SEARCH_PATH" TO public',
    'comments and batch' => 'SELECT 1; SET /* scope */ LOCAL /* setting */ "search_path" TO public',
    'quoted role' => 'SET "role" TO application_role',
    'quoted authorization setting' => 'SET "session_authorization" TO application_role',
    'session authorization' => 'SET SESSION AUTHORIZATION application_role',
    'local session authorization' => 'SET LOCAL SESSION AUTHORIZATION application_role',
    'reset' => 'RESET "search_path"',
    'quoted configuration function' => 'SELECT "pg_catalog"."set_config"(\'search_path\', \'public\', true)',
    'function after string comment lookalike' => "SELECT '--'; SELECT set_config('search_path', 'public', true)",
    'function after operator clause' => "SELECT '' OPERATOR(pg_catalog.||) set_config('search_path', 'public', false)",
    'quoted function after operator and comment' => "SELECT '' OPERATOR(pg_catalog.||) /* scope */ \"set_config\"('search_path', 'public', false)",
    'global variable' => 'SET GLOBAL sql_mode = \'\'',
    'create schema' => 'CREATE /* scope */ SCHEMA unwanted',
    'procedural block' => 'DO $$ BEGIN PERFORM set_config(\'search_path\', \'public\', false); END $$',
    'stored procedure' => 'CALL change_scope()',
    'prepared execution' => 'EXECUTE change_scope',
    'prepare scoped change' => 'PREPARE change_scope AS SELECT set_config(\'search_path\', \'public\', false)',
    'routine body' => 'CREATE OR REPLACE FUNCTION change_scope() RETURNS void LANGUAGE plpgsql AS $$ BEGIN PERFORM set_config(\'search_path\', \'public\', false); END $$',
]);

it('permits replay-local settings and text that mentions session changes', function (string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['original'], ['align-1234-abcde'], 'pgsql');
    expect(true)->toBeTrue();
})->with([
    'timeout' => 'SET LOCAL "statement_timeout" TO 1000',
    'literal' => 'SELECT \'SET LOCAL "search_path" TO public\'',
    'dollar literal' => 'SELECT $$SELECT set_config(\'search_path\', \'public\', true)$$',
    'comment' => 'SELECT 1 /* SET LOCAL "search_path" TO public */',
    'ordinary column' => 'SELECT "set_config" FROM settings',
]);

it('refuses global destructive replay SQL before execution', function (string $driver, string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['original'], ['align-1234-abcde'], $driver))
        ->toThrow(RuntimeException::class, 'global scope');
})->with(['pgsql', 'mysql'])->with([
    'schema' => 'DROP SCHEMA original CASCADE',
    'database' => 'DROP DATABASE IF EXISTS original',
    'comments' => "/* user's migration */ DROP /* scope */ SCHEMA original",
    'multiple statements' => 'SELECT 1; DROP DATABASE original',
    'global drop after column drop' => 'ALTER TABLE sample_records DROP role; DROP ROLE application_role',
    'empty statements and comments' => '; /* migration */ ; DROP /* scope */ SCHEMA original',
    'role' => 'DROP ROLE application_role',
    'owned objects' => 'DROP OWNED BY application_role CASCADE',
]);

it('allows local drops and SQL text mentioning global drops', function (string $driver, string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['original'], ['align-1234-abcde'], $driver);
    expect(true)->toBeTrue();
})->with(['pgsql', 'mysql'])->with([
    'table' => 'DROP TABLE IF EXISTS old_table',
    'column' => 'ALTER TABLE sample_records DROP COLUMN old_column',
    'implicit column' => 'ALTER TABLE sample_records DROP role',
    'multiple column clauses' => 'ALTER TABLE sample_records DROP role, DROP schema',
    'implicit column after another statement' => 'SELECT 1; ALTER TABLE sample_records DROP role',
    'literal' => "SELECT 'DROP SCHEMA original CASCADE'",
    'quoted identifier' => 'SELECT "DROP", "SCHEMA" FROM sample_records',
    'comment' => 'SELECT 1 /* DROP DATABASE original */',
]);

it('limits replay DDL to namespaces owned by the current run', function (string $driver, string $sql): void {
    expect(fn () => ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde'], $driver))
        ->toThrow(RuntimeException::class);
})->with(['pgsql', 'mysql'])->with([
    'unselected schema' => 'ALTER TABLE public.sample_links ADD COLUMN note text',
    'quoted namespace' => 'ALTER TABLE "public"."sample_links" ADD COLUMN note text',
    'another run' => 'ALTER TABLE "align-5678-abcde".sample_links ADD COLUMN note text',
    'comments and statements' => 'SELECT 1; /* migration */ ALTER TABLE public /* target */ .sample_links ADD COLUMN note text',
    'create index' => 'CREATE INDEX sample_link_note ON public.sample_links (note)',
    'multiple targets' => 'DROP TABLE "align-1234-abcde".sample_links, public.sample_links',
    'move into original' => 'ALTER TABLE sample_links SET SCHEMA public',
    'rename schema' => 'ALTER SCHEMA public RENAME TO renamed',
    'mysql rename destination' => 'RENAME TABLE sample_links TO public.sample_links',
    'external column comment' => "COMMENT ON COLUMN public.sample_links.note IS 'updated'",
    'explain executes ddl' => 'EXPLAIN ANALYZE CREATE TABLE public.sample_links AS SELECT 1 AS id',
    'explain options execute ddl' => 'EXPLAIN (ANALYZE true) CREATE TABLE public.sample_links AS SELECT 1 AS id',
    'digit-prefixed database' => 'ALTER TABLE 123outside.sample_links ADD COLUMN note text',
]);

it('allows owned replay DDL and read-only catalog qualifiers', function (string $driver, string $sql): void {
    ReplaySqlGuard::assertAllowedNamespaces($sql, ['fixture_scope'], ['align-1234-abcde', 'align-5678-abcde'], $driver);
    expect(true)->toBeTrue();
})->with(['pgsql', 'mysql'])->with([
    'owned table' => 'ALTER TABLE "align-1234-abcde".sample_links ADD COLUMN note text',
    'owned foreign key' => 'ALTER TABLE "align-1234-abcde".sample_links ADD FOREIGN KEY (sample_item_id) REFERENCES "align-5678-abcde".sample_items (id)',
    'owned mysql table' => 'ALTER TABLE `align-1234-abcde`.`sample_links` ADD COLUMN note text',
    'catalog aliases' => 'SELECT n.nspname FROM pg_catalog.pg_namespace AS n',
    'information schema' => 'SELECT t.table_name FROM information_schema.tables AS t',
    'read after ddl' => 'CREATE TABLE sample_links (id integer); SELECT t.id FROM sample_links AS t',
    'literal lookalike' => "CREATE TABLE notes (note text DEFAULT 'public.sample_links')",
    'column comment' => "COMMENT ON COLUMN sample_links.note IS 'a note'",
    'qualified column comment' => "COMMENT ON COLUMN \"align-1234-abcde\".sample_links.note IS 'a note'",
    'decimal default' => 'CREATE TABLE amounts (value numeric(12, 2) DEFAULT 1.25)',
    'exponent default' => 'CREATE TABLE amounts (value double precision DEFAULT 1.25e-2)',
]);
