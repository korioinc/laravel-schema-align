<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Replay\ReplayNamespace;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\NamespaceNormalizer;
use Korioinc\SchemaAlign\Schema\Snapshot;

it('normalizes names reserved by excluded ledger relations without changing object names', function (): void {
    $physical = 'align-1021-abcde';
    $snapshot = new Snapshot([], ['excluded_relations' => [
        $physical.'.migrations' => ['schema' => $physical, 'name' => 'migrations'],
        $physical.'.'.$physical => ['schema' => $physical, 'name' => $physical],
        'other.migrations' => ['schema' => 'other', 'name' => 'migrations'],
    ]], []);
    $logical = NamespaceNormalizer::normalize($snapshot, ['fixture_scope' => $physical]);

    expect($logical->platform['excluded_relations'])->toBe([
        'fixture_scope.'.$physical => ['name' => $physical, 'schema' => 'fixture_scope'],
        'fixture_scope.migrations' => ['name' => 'migrations', 'schema' => 'fixture_scope'],
        'other.migrations' => ['name' => 'migrations', 'schema' => 'other'],
    ])->and(array_keys($snapshot->platform['excluded_relations']))->toContain($physical.'.migrations');
});

it('normalizes qualified namespace semantics without changing identity names or literals', function () {
    $physical = 'align-1021-abcde';
    $snapshot = new Snapshot(['database' => 'original', 'database_oid' => '123'], ['schemas' => [$physical]], [
        $physical.'.sample_links' => ['schema' => $physical, 'name' => 'sample_links', 'columns' => [
            'id' => ['type' => 'bigint', 'nullable' => false, 'default' => 'nextval(\'"'.$physical.'".sample_links_id_seq\'::regclass)', 'identity' => '', 'generated' => '', 'collation' => null],
            'note' => ['type' => 'text', 'nullable' => true, 'default' => "'{$physical}.literal'::text", 'identity' => '', 'generated' => '', 'collation' => 'pg_catalog."default"'],
        ], 'constraints' => ['explicit_'.$physical => ['kind' => 'f', 'columns' => ['id'], 'foreign_schema' => $physical, 'foreign_table' => 'sample_values', 'foreign_columns' => ['id'], 'backing_index' => null, 'definition' => 'FOREIGN KEY (id) REFERENCES "'.$physical.'".sample_values(id)', 'signature' => 'old']], 'indexes' => []],
    ], [], ['create_sample_links']);

    $logical = NamespaceNormalizer::normalize($snapshot, ['fixture_scope' => $physical]);
    expect($logical->identity)->toBe($snapshot->identity)
        ->and($snapshot->platform['schemas'])->toBe([$physical])
        ->and($logical->platform['schemas'])->toBe(['fixture_scope'])
        ->and($logical->tables['fixture_scope.sample_links']['columns']['id']['default'])->toBe("nextval('fixture_scope.sample_links_id_seq'::regclass)")
        ->and($logical->tables['fixture_scope.sample_links']['columns']['note']['default'])->toBe("'{$physical}.literal'::text")
        ->and($logical->tables['fixture_scope.sample_links']['constraints']['explicit_'.$physical]['definition'])->toBe('FOREIGN KEY (id) REFERENCES fixture_scope.sample_values(id)')
        ->and($logical->tables['fixture_scope.sample_links']['constraints']['explicit_'.$physical]['foreign_schema'])->toBe('fixture_scope')
        ->and($logical->migrationNames)->toBe(['create_sample_links']);
});

it('rejects ambiguous mappings and dynamic namespace identities', function () {
    $snapshot = new Snapshot([], ['schemas' => ['physical']], [
        'physical.t' => ['schema' => 'physical', 'name' => 't', 'columns' => ['id' => ['type' => 'integer', 'default' => "nextval(('physical.' || name)::regclass)"]], 'constraints' => [], 'indexes' => []],
    ]);
    expect(fn () => NamespaceNormalizer::normalize($snapshot, ['public' => 'physical', 'other' => 'physical']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NamespaceNormalizer::normalize($snapshot, ['public' => 'physical']))->toThrow(RuntimeException::class, 'unsupported_namespace_expression');
});

it('preserves real PostgreSQL catalog fingerprints across repeated qualified replays', function (string $separator) {
    $dsn = getenv('SCHEMA_ALIGN_TEST_DSN');

    if (! $dsn) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated namespace normalization proof.');
    }

    if (! preg_match('/\Apgsql:host=(?:127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=schema-align-tests(?:-[a-z0-9]+)?\z/', $dsn)
        || getenv('SCHEMA_ALIGN_TEST_USER') !== 'schema_align_test') {
        throw new RuntimeException('Namespace tests require the isolated loopback test database.');
    }
    $pdo = new PDO($dsn, getenv('SCHEMA_ALIGN_TEST_USER'), getenv('SCHEMA_ALIGN_TEST_PASSWORD'));
    $suffix = bin2hex(random_bytes(5));
    $logical = ['sa_norm'.$separator.$suffix, 'sa_norm_second'.$separator.$suffix];
    $runs = [$logical, ['align-1021-'.$suffix, 'align-1021-b'.$suffix], ['align-1022-'.$suffix, 'align-1022-b'.$suffix]];
    $created = [];
    $snapshots = [];

    try {
        foreach ($runs as $schemas) {
            foreach ($schemas as $schema) {
                $pdo->exec('CREATE SCHEMA "'.$schema.'"');
                $created[] = $schema;
            }
            [$first, $second] = array_map(fn ($schema) => '"'.$schema.'"', $schemas);
            $pdo->exec('CREATE TABLE '.$second.'.parents (id bigint GENERATED ALWAYS AS IDENTITY, CONSTRAINT parents_pk PRIMARY KEY (id))');
            $pdo->exec('CREATE TABLE '.$first.'.sample_links (id serial, parent_id bigint, note text COLLATE "C" DEFAULT \'align-1021-literal\', CONSTRAINT sample_links_pk PRIMARY KEY (id), CONSTRAINT sample_links_fk FOREIGN KEY (parent_id) REFERENCES '.$second.'.parents(id), CONSTRAINT sample_links_check CHECK (note <> \'align-1021-literal\'))');
            $pdo->exec('CREATE INDEX "align_1021_explicit_index" ON '.$first.'.sample_links ((lower(note)) COLLATE "C") WHERE note <> \'align-1021-literal\'');
            $pdo->exec('CREATE UNIQUE INDEX sample_links_note_unique ON '.$first.'.sample_links (note DESC NULLS LAST) INCLUDE (parent_id)');
            $physical = (new PostgresCatalog($pdo, $schemas))->inspect();
            expect($physical->unsupported)->toBe([]);
            $snapshots[] = $schemas === $logical ? $physical : NamespaceNormalizer::normalize($physical, array_combine($logical, $schemas));
        }
        expect($snapshots[1]->fingerprint())->toBe($snapshots[0]->fingerprint())
            ->and($snapshots[2]->fingerprint())->toBe($snapshots[0]->fingerprint());
        $comparison = (new SchemaComparator)->compare($snapshots[0], $snapshots[1]);
        expect($comparison['errors'])->toBe([])->and($comparison['differences'])->toBe([]);
    } finally {
        foreach (array_reverse($created) as $schema) {
            $pdo->exec('DROP TABLE IF EXISTS "'.$schema.'".sample_links');
        }
        foreach (array_reverse($created) as $schema) {
            $pdo->exec('DROP TABLE IF EXISTS "'.$schema.'".parents');
            $pdo->exec('DROP SCHEMA "'.$schema.'" RESTRICT');
        }
    }
})->with(['ordinary namespaces' => '_', 'dollar namespaces' => '$']);

it('excludes MySQL ownership metadata only for the exact verified replay resource', function () {
    $dsn = getenv('SCHEMA_ALIGN_MYSQL_DSN');

    if (! $dsn) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_DSN for isolated marker proof.');
    }

    if (! preg_match('/\Amysql:host=(?:127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=schema-align-tests(?:-[a-z0-9]+)?\z/', $dsn)
        || getenv('SCHEMA_ALIGN_MYSQL_USER') !== 'schema_align_test') {
        throw new RuntimeException('Namespace tests require the isolated loopback test database.');
    }

    if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1'
        || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER') || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD')) {
        $this->markTestSkipped('Explicit disposable MySQL admin opt-in is required for complete ownership visibility.');
    }
    $namespaceDatabaseLock = fopen(sys_get_temp_dir().'/schema-align-mysql-'.hash('sha256', $dsn).'.lock', 'c');

    if ($namespaceDatabaseLock === false || ! flock($namespaceDatabaseLock, LOCK_EX)) {
        throw new RuntimeException('Cannot serialize disposable MySQL namespace fixtures.');
    }
    $connect = fn () => new PDO($dsn, getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'));
    $pdo = $connect();
    $identity = $pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS `database`')->fetch(PDO::FETCH_ASSOC);
    $manager = new ReplayNamespace($pdo, ['instance' => 'normalizer-tests', 'database' => $identity['database']], $identity, ['@database']);
    $resource = $manager->create();

    try {
        $replay = $connect();
        $replay->exec('USE `'.$resource['namespaces'][0].'`');
        $replay->exec('CREATE TABLE sample_links (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $ordinary = (new MySqlCatalog($replay))->inspect();
        expect($ordinary->tables)->toHaveKey('@database.__schema_align_run');
        $owned = (new MySqlCatalog($replay, 'migrations', $resource))->inspect();
        expect(array_keys($owned->tables))->toBe(['@database.sample_links'])
            ->and($owned->identity['database'])->toBe($resource['namespaces'][0]);
        $wrong = $resource;
        $wrong['run_id'] = str_repeat('f', 32);
        expect(fn () => (new MySqlCatalog($replay, 'migrations', $wrong))->inspect())->toThrow(RuntimeException::class, 'does not match');
        $replay->exec("INSERT INTO __schema_align_run (payload) VALUES ('{}')");
        expect(fn () => (new MySqlCatalog($replay, 'migrations', $resource))->inspect())->toThrow(RuntimeException::class, 'exactly one');
        $replay->exec("DELETE FROM __schema_align_run WHERE payload = '{}'");
        $replay->exec('ALTER TABLE __schema_align_run ADD COLUMN extra int');
        expect(fn () => (new MySqlCatalog($replay, 'migrations', $resource))->inspect())->toThrow(RuntimeException::class, 'invalid table shape');
        $replay->exec('ALTER TABLE __schema_align_run DROP COLUMN extra');
    } finally {
        $replay = null;
        // This fixture created this exact database exclusively; no shared fixture tables are removed.
        $pdo->exec('DROP DATABASE `'.$resource['namespaces'][0].'`');
        $manager->release();
        flock($namespaceDatabaseLock, LOCK_UN);
        fclose($namespaceDatabaseLock);
    }
});

it('keeps escaped dollar quoted and commented namespace lookalikes intact', function () {
    $expression = <<<'SQL'
'physical.literal' || E'physical.escaped' || $$physical.dollar$$ || $tag$physical.tagged$tag$ /* outer /* nested */ physical.comment */ || physical.function('physical.literal')
SQL;
    $snapshot = new Snapshot([], ['schemas' => ['physical']], [
        'physical.t' => ['schema' => 'physical', 'name' => 't', 'columns' => ['v' => ['type' => 'physical.custom_type[]', 'default' => $expression, 'collation' => 'physical.custom_collation']], 'constraints' => [], 'indexes' => []],
    ]);
    $normalized = NamespaceNormalizer::normalize($snapshot, ['logical' => 'physical']);
    $expected = <<<'SQL'
'physical.literal' || E'physical.escaped' || $$physical.dollar$$ || $tag$physical.tagged$tag$ /* outer /* nested */ physical.comment */ || logical.function('physical.literal')
SQL;
    expect($normalized->tables['logical.t']['columns']['v']['default'])->toBe($expected)
        ->and($normalized->tables['logical.t']['columns']['v']['type'])->toBe('logical.custom_type[]')
        ->and($normalized->tables['logical.t']['columns']['v']['collation'])->toBe('logical.custom_collation');
});

it('rejects runtime namespace lookups instead of rewriting their string arguments', function () {
    $snapshot = new Snapshot([], ['schemas' => ['physical']], [
        'physical.t' => ['schema' => 'physical', 'name' => 't', 'columns' => ['id' => ['type' => 'regclass', 'default' => "to_regclass('physical.sample_links')"]], 'constraints' => [], 'indexes' => []],
    ]);
    expect(fn () => NamespaceNormalizer::normalize($snapshot, ['public' => 'physical']))
        ->toThrow(RuntimeException::class, 'unsupported_namespace_expression');
});
