<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;

it('canonicalizes maps recursively while preserving ordered lists', function () {
    expect(Snapshot::canonical(['z' => ['b' => 2, 'a' => 1], 'a' => ['z', 'a']]))
        ->toBe('{"a":["z","a"],"z":{"a":1,"b":2}}');
});

it('round trips snapshots and excludes identity and the ledger from fingerprints', function () {
    $snapshot = new Snapshot(['database' => 'one'], ['server_major' => 15], []);
    $copy = Snapshot::fromArray($snapshot->toArray());
    $copy->identity = ['database' => 'two'];
    $copy->migrationNames = ['2026_01_01_example'];

    expect($copy->fingerprint())->toBe($snapshot->fingerprint())
        ->and(Snapshot::fromArray($snapshot->toArray())->toArray())->toBe($snapshot->toArray());

    $copy->platform['server_major'] = 16;
    expect($copy->fingerprint())->not->toBe($snapshot->fingerprint());
    $copy->platform = $snapshot->platform;
    $copy->unsupported = [['reason' => 'trigger']];
    expect($copy->fingerprint())->not->toBe($snapshot->fingerprint());
});

it('rejects malformed snapshot documents', function (array $data) {
    Snapshot::fromArray($data);
})->with([
    'missing fields' => [[]],
    'invalid table' => [['identity' => [], 'platform' => [], 'tables' => ['public.foo' => []], 'unsupported' => [], 'migrationNames' => []]],
    'invalid ledger' => [['identity' => [], 'platform' => [], 'tables' => [], 'unsupported' => [], 'migrationNames' => [null]]],
    'unknown field' => [['identity' => [], 'platform' => [], 'tables' => [], 'unsupported' => [], 'migrationNames' => [], 'extra' => true]],
])->throws(InvalidArgumentException::class);

it('reads real PostgreSQL catalogs and preserves caller transactions', function (): void {
    $dsn = getenv('SCHEMA_ALIGN_TEST_DSN');

    if (! $dsn) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated PostgreSQL catalog verification.');
    }

    if (! preg_match('/\Apgsql:host=(?:127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=schema-align-tests(?:-[a-z0-9]+)?\z/D', $dsn, $parts)
        || (int) $parts[1] < 1 || (int) $parts[1] > 65535 || getenv('SCHEMA_ALIGN_TEST_USER') !== 'schema_align_test') {
        throw new RuntimeException('Catalog tests require an explicit loopback disposable schema-align-tests database.');
    }
    $pdo = new PDO($dsn, getenv('SCHEMA_ALIGN_TEST_USER') ?: null, getenv('SCHEMA_ALIGN_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if ($pdo->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn()) {
        throw new RuntimeException('Catalog tests refuse superuser connections.');
    }
    $schema = 'sa_catalog_'.bin2hex(random_bytes(6));
    $q = '"'.$schema.'"';
    $pdo->exec('CREATE SCHEMA '.$q);

    try {
        $pdo->exec('CREATE TABLE '.$q.'.migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)');
        $pdo->exec("INSERT INTO {$q}.migrations (migration, batch) VALUES ('example', 1)");
        $pdo->exec('CREATE TABLE '.$q.'.parents (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, code varchar(31) UNIQUE)');
        $pdo->exec('CREATE TABLE '.$q.'.items (id serial PRIMARY KEY, parent_id bigint REFERENCES '.$q.'.parents(id) ON DELETE SET NULL, price numeric(12, 3) DEFAULT 1.250, title varchar(47) COLLATE "C", created_at timestamp(4) with time zone, total numeric GENERATED ALWAYS AS (price * 2) STORED, CONSTRAINT positive CHECK (price > 0))');
        $pdo->exec('CREATE UNIQUE INDEX item_title ON '.$q.'.items (title DESC NULLS LAST) INCLUDE (parent_id) NULLS NOT DISTINCT WHERE price > 0');
        $pdo->exec('CREATE INDEX item_expression ON '.$q.'.items ((lower(title)) COLLATE "C")');
        $catalog = new PostgresCatalog($pdo, [$schema]);
        $beforeExpression = $catalog->inspect();
        $pdo->exec('CREATE TABLE '.$q.'.expression_collation (name text CHECK ((name COLLATE "en-x-icu") > \'a\'))');
        $withExpression = $catalog->inspect();
        $versionMismatch = Snapshot::fromArray($withExpression->toArray());

        if (! isset($versionMismatch->platform['collations']['pg_catalog.en-x-icu'])) {
            throw new RuntimeException('CHECK-only collation dependency was not inspected.');
        }
        $versionMismatch->platform['collations']['pg_catalog.en-x-icu']['collversion'] = 'mismatched-test-version';
        $versionMismatch->platform['collations']['pg_catalog.en-x-icu']['actual_version'] = 'mismatched-test-version';
        expect((new SchemaComparator)->compare($withExpression, $versionMismatch)['errors'])->not->toBe([]);
        $pdo->exec('DROP TABLE '.$q.'.expression_collation');
        expect($catalog->inspect()->fingerprint())->toBe($beforeExpression->fingerprint());
        $snapshot = $catalog->inspect();
        expect($pdo->inTransaction())->toBeFalse()
            ->and($snapshot->unsupported)->toBe([])
            ->and($snapshot->migrationNames)->toBe(['example'])
            ->and(array_keys($snapshot->tables))->toBe([$schema.'.items', $schema.'.parents'])
            ->and($snapshot->tables[$schema.'.items']['columns']['price']['type'])->toBe('numeric(12,3)')
            ->and($snapshot->tables[$schema.'.items']['columns']['title']['type'])->toBe('character varying(47)')
            ->and($snapshot->tables[$schema.'.items']['columns']['created_at']['type'])->toBe('timestamp(4) with time zone')
            ->and($snapshot->tables[$schema.'.items']['columns']['total']['generated'])->toBe('s')
            ->and($snapshot->tables[$schema.'.parents']['columns']['id']['identity'])->toBe('a')
            ->and($snapshot->tables[$schema.'.items']['indexes']['item_title']['nulls_not_distinct'])->toBeTrue()
            ->and($snapshot->tables[$schema.'.items']['indexes']['item_title']['keys'][0]['order'])->toBe('DESC')
            ->and($snapshot->tables[$schema.'.items']['indexes']['item_title']['keys'][0]['nulls'])->toBe('LAST')
            ->and($snapshot->tables[$schema.'.items']['indexes']['item_title']['include'])->toBe(['parent_id'])
            ->and(count($snapshot->tables[$schema.'.items']['indexes']))->toBe(2)
            ->and(Snapshot::fromArray($snapshot->toArray())->fingerprint())->toBe($snapshot->fingerprint());
        $pdo->beginTransaction();
        $pdo->exec('LOCK TABLE '.$q.'.items IN ACCESS EXCLUSIVE MODE');
        $pdo->exec('ALTER TABLE '.$q.'.items ADD COLUMN pending integer');
        $changed = $catalog->inspect();
        expect($pdo->inTransaction())->toBeTrue()
            ->and($changed->tables[$schema.'.items']['columns'])->toHaveKey('pending');
        $pdo->rollBack();
        expect($catalog->inspect()->fingerprint())->toBe($snapshot->fingerprint());
        $pdo->exec('ALTER INDEX '.$q.'.item_title RENAME TO item_renamed');
        $renamed = $catalog->inspect();
        expect($renamed->tables[$schema.'.items']['indexes']['item_renamed']['signature'])
            ->toBe($snapshot->tables[$schema.'.items']['indexes']['item_title']['signature']);
        $pdo->exec('CREATE TABLE '.$q.'.oid_probe (value integer)');
        $beforeRecreate = $catalog->inspect();
        $oldOid = $pdo->query("SELECT '".$q.".oid_probe'::regclass::oid")->fetchColumn();
        $pdo->exec('DROP TABLE '.$q.'.oid_probe');
        $pdo->exec('CREATE TABLE '.$q.'.oid_probe (value integer)');
        $newOid = $pdo->query("SELECT '".$q.".oid_probe'::regclass::oid")->fetchColumn();
        expect($oldOid)->not->toBe($newOid)
            ->and($catalog->inspect()->fingerprint())->toBe($beforeRecreate->fingerprint());
        $pdo->exec('ALTER TABLE '.$q.'.items RENAME CONSTRAINT items_pkey TO renamed_primary');
        expect($catalog->inspect()->tables[$schema.'.items']['constraints']['renamed_primary']['signature'])
            ->toBe($snapshot->tables[$schema.'.items']['constraints']['items_pkey']['signature']);
        $pdo->exec('CREATE VIEW '.$q.'.item_view AS SELECT id FROM '.$q.'.items');
        expect(array_column($catalog->inspect()->unsupported, 'reason'))->toContain('relation_properties', 'rule');
        $pdo->exec('ALTER TABLE '.$q.'.migrations DROP COLUMN batch');
        expect(fn () => $catalog->inspect())->toThrow(RuntimeException::class, 'missing required columns');
        expect($pdo->inTransaction())->toBeFalse();
        $pdo->beginTransaction();
        expect(fn () => $catalog->inspect())->toThrow(RuntimeException::class, 'missing required columns');
        expect($pdo->inTransaction())->toBeTrue();
        $pdo->rollBack();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('DROP SCHEMA '.$q.' CASCADE');
    }
});
