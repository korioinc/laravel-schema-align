<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\NamespaceNormalizer;
use Korioinc\SchemaAlign\Schema\Snapshot;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;

beforeEach(function (): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN/USER/PASSWORD for the isolated PostgreSQL acceptance target.');
    }
    $this->acceptance = new ApplyFixture($this->app);
});

afterEach(function (): void {
    if (isset($this->acceptance)) {
        $this->acceptance->close();
    }
});

function postgresAcceptanceSnapshot(ApplyFixture $fixture): Snapshot
{
    return (new PostgresCatalog($fixture->pdo, [$fixture->schema]))->inspect();
}

/** @return array<string, mixed> */
function postgresAcceptanceComparison(ApplyFixture $fixture, Snapshot $desired): array
{
    $actual = postgresAcceptanceSnapshot($fixture);
    $comparison = (new SchemaComparator)->compare($desired, $actual);
    $comparison['desired'] = $desired->toArray();
    $comparison['actual'] = $actual->toArray();
    $comparison['source'] = ['revision' => 'isolated-postgres-acceptance'];
    $comparison['binding'] = TargetConnection::binding(
        config('database.connections.schema_align_testing'), 'isolated-tests', app()->environment(),
    );

    return $comparison;
}

it('creates a missing table with named not null, primary and unique keys and an index while preserving rows', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $baseline = postgresAcceptanceSnapshot($fixture);
    expect(postgresAcceptanceComparison($fixture, $baseline)['differences'])->toBe([]);

    $fixture->pdo->exec('CREATE TABLE '.$q.'.sample_metrics (id integer CONSTRAINT sample_metric_id_required NOT NULL, code text CONSTRAINT sample_metric_code_required NOT NULL, title text, CONSTRAINT sample_metric_pk PRIMARY KEY (id), CONSTRAINT sample_metric_code_unique UNIQUE (code))');
    $fixture->pdo->exec('CREATE INDEX sample_metric_title_idx ON '.$q.'.sample_metrics (title)');
    $desired = postgresAcceptanceSnapshot($fixture);
    // Remove only our desired-state fixture; retain the pre-existing sample_items and data.
    $fixture->pdo->exec('DROP TABLE '.$q.'.sample_metrics');
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->toBe([])
        ->and(array_column($comparison['differences'], 'kind'))->toContain('create_table', 'add_constraint', 'create_index')
        ->and(array_unique(array_column($comparison['differences'], 'automatic')))->toBe([true]);
    $plan = $fixture->align->pin($comparison, ['all-safe']);
    $fixture->align->apply($plan['id'], $plan['signature']);

    expect(postgresAcceptanceComparison($fixture, $desired)['differences'])->toBe([])
        ->and($fixture->itemName())->toBe('Sample item A');
    $fixture->pdo->exec("INSERT INTO {$q}.sample_metrics (id, code, title) VALUES (1, 'first', 'Created by approved plan')");
    expect($fixture->pdo->query('SELECT title FROM '.$q.'.sample_metrics WHERE id = 1')->fetchColumn())->toBe('Created by approved plan');
    foreach (["(1, 'second', 'duplicate primary')", "(2, 'first', 'duplicate unique')", "(3, NULL, 'null required')"] as $row) {
        expect(fn () => $fixture->pdo->exec('INSERT INTO '.$q.'.sample_metrics (id, code, title) VALUES '.$row))->toThrow(PDOException::class);
    }

    if ((int) $fixture->pdo->query('SHOW server_version_num')->fetchColumn() >= 180000) {
        $names = $fixture->pdo->query("SELECT conname FROM pg_constraint WHERE conrelid = '{$q}.sample_metrics'::regclass AND contype = 'n' ORDER BY conname")->fetchAll(PDO::FETCH_COLUMN);
        expect($names)->toBe(['sample_metric_code_required', 'sample_metric_id_required']);
    }
});

it('applies standalone index and unique constraint name changes and becomes clean', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $fixture->pdo->exec('CREATE INDEX sample_item_name_old ON '.$q.'.sample_items (name)');
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items ADD CONSTRAINT sample_item_name_unique_old UNIQUE (name)');
    $fixture->pdo->exec('ALTER INDEX '.$q.'.sample_item_name_old RENAME TO sample_item_name_new');
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items RENAME CONSTRAINT sample_item_name_unique_old TO sample_item_name_unique_new');
    $desired = postgresAcceptanceSnapshot($fixture);
    $fixture->pdo->exec('ALTER INDEX '.$q.'.sample_item_name_new RENAME TO sample_item_name_old');
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items RENAME CONSTRAINT sample_item_name_unique_new TO sample_item_name_unique_old');
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->toBe([])
        ->and(array_column($comparison['differences'], 'kind'))->toBe(['rename_constraint', 'rename_index']);
    $plan = $fixture->align->pin($comparison, ['all-safe']);
    $fixture->align->apply($plan['id'], $plan['signature']);
    expect(postgresAcceptanceComparison($fixture, $desired)['differences'])->toBe([])
        ->and($fixture->itemName())->toBe('Sample item A');
    expect(fn () => $fixture->pdo->exec("INSERT INTO {$q}.sample_items VALUES (2, 'Sample item A')"))->toThrow(PDOException::class);
});

it('keeps a unique constraint distinct from a standalone unique index', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items ADD CONSTRAINT sample_item_unique UNIQUE (name)');
    $desired = postgresAcceptanceSnapshot($fixture);
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items DROP CONSTRAINT sample_item_unique');
    $fixture->pdo->exec('CREATE UNIQUE INDEX sample_item_unique ON '.$q.'.sample_items (name)');
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->toBe([])
        ->and(array_column($comparison['differences'], 'kind'))->toBe(['add_constraint', 'extra_index']);
    foreach ($comparison['differences'] as $difference) {
        expect($difference['automatic'])->toBeFalse()->and($difference['sql'])->toBe([]);
    }
    expect(fn () => $fixture->align->pin($comparison, ['all-safe']))->toThrow(RuntimeException::class)
        ->and($fixture->itemName())->toBe('Sample item A');
});

it('commits a replayed index in a dollar namespace without a false checkpoint mismatch', function (): void {
    $fixture = $this->acceptance;
    $schema = $fixture->schema.'$target';
    $quotedSchema = '"'.$schema.'"';
    $fixture->pdo->exec('CREATE SCHEMA '.$quotedSchema);

    try {
        $fixture->pdo->exec('CREATE TABLE '.$quotedSchema.'.sample_items (id integer PRIMARY KEY, name text NOT NULL)');
        $fixture->pdo->exec("INSERT INTO {$quotedSchema}.sample_items VALUES (1, 'Sample item A')");
        $fixture->pdo->exec('CREATE INDEX sample_item_name_idx ON '.$fixture->quotedSchema.'.sample_items (name)');
        $desired = NamespaceNormalizer::normalize(postgresAcceptanceSnapshot($fixture), [$schema => $fixture->schema]);
        $actual = (new PostgresCatalog($fixture->pdo, [$schema]))->inspect();
        $comparison = (new SchemaComparator)->compare($desired, $actual);
        $comparison['desired'] = $desired->toArray();
        $comparison['actual'] = $actual->toArray();
        $comparison['source'] = ['revision' => 'isolated-postgres-acceptance'];
        $comparison['binding'] = TargetConnection::binding(
            config('database.connections.schema_align_testing'), 'isolated-tests', app()->environment(),
        );
        config(['schema-align.schemas' => [$schema]]);
        $plan = $fixture->align->pin($comparison, ['all-safe']);
        $fixture->align->apply($plan['id'], $plan['signature']);

        expect((new PostgresCatalog($fixture->pdo, [$schema]))->inspect()->fingerprint())->toBe($desired->fingerprint())
            ->and($fixture->pdo->query('SELECT name FROM '.$quotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Sample item A');
    } finally {
        $fixture->pdo->exec('DROP SCHEMA '.$quotedSchema.' CASCADE');
    }
});

it('blocks pinning when an unsupported view makes the comparison incomplete', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items ADD COLUMN note text');
    $desired = postgresAcceptanceSnapshot($fixture);
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items DROP COLUMN note');
    $fixture->pdo->exec('CREATE VIEW '.$q.'.sample_item_view AS SELECT id FROM '.$q.'.sample_items');
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->not->toBe([]);
    expect(fn () => $fixture->align->pin($comparison, ['all-safe']))->toThrow(RuntimeException::class, 'Incomplete comparison')
        ->and(glob($fixture->directory.'/*.plan.json') ?: [])->toBe([])
        ->and($fixture->itemName())->toBe('Sample item A');
});

it('recognizes PostgreSQL type aliases and equivalent parsed defaults', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $fixture->pdo->exec("CREATE TABLE {$q}.typed_values (label varchar(40) DEFAULT 'ready', amount decimal(12, 3) DEFAULT 1.250, occurred_at timestamptz(4) DEFAULT now())");
    $desired = postgresAcceptanceSnapshot($fixture);
    $fixture->pdo->exec('DROP TABLE '.$q.'.typed_values');
    $fixture->pdo->exec("CREATE TABLE {$q}.typed_values (label character varying(40) DEFAULT 'ready'::character varying, amount numeric(12,3) DEFAULT 1.250, occurred_at timestamp(4) with time zone DEFAULT now())");
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->toBe([])->and($comparison['differences'])->toBe([]);
});

it('reports meaningful varchar numeric timestamp precision and default drift for manual review', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $fixture->pdo->exec("CREATE TABLE {$q}.typed_values (label varchar(40), amount numeric(12,3), occurred_at timestamptz(4), state text DEFAULT 'ready')");
    $desired = postgresAcceptanceSnapshot($fixture);
    $fixture->pdo->exec('DROP TABLE '.$q.'.typed_values');
    $fixture->pdo->exec("CREATE TABLE {$q}.typed_values (label varchar(41), amount numeric(12,2), occurred_at timestamptz(3), state text DEFAULT 'pending')");
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->toBe([])
        ->and(array_column($comparison['differences'], 'name'))->toBe(['amount', 'label', 'occurred_at', 'state']);
    foreach ($comparison['differences'] as $difference) {
        expect($difference['kind'])->toBe('change_column')
            ->and($difference['automatic'])->toBeFalse()->and($difference['sql'])->toBe([]);
    }
    expect(fn () => $fixture->align->pin($comparison, ['all-safe']))->toThrow(RuntimeException::class);
});

it('requires manual mapping for missing required and extra columns without destructive SQL', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $fixture->pdo->exec('CREATE TABLE '.$q.'.column_mapping (desired_name text NOT NULL)');
    $desired = postgresAcceptanceSnapshot($fixture);
    $fixture->pdo->exec('DROP TABLE '.$q.'.column_mapping');
    $fixture->pdo->exec('CREATE TABLE '.$q.'.column_mapping (legacy_name text NOT NULL)');
    $fixture->pdo->exec("INSERT INTO {$q}.column_mapping VALUES ('Preserve this legacy value')");
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->toBe([])->and($comparison['warnings'])->not->toBe([])
        ->and(array_column($comparison['differences'], 'kind'))->toContain('add_column', 'extra_column');
    foreach ($comparison['differences'] as $difference) {
        expect($difference['automatic'])->toBeFalse()->and($difference['sql'])->toBe([]);
    }
    expect(fn () => $fixture->align->pin($comparison, ['all-safe']))->toThrow(RuntimeException::class)
        ->and($fixture->pdo->query('SELECT legacy_name FROM '.$q.'.column_mapping')->fetchColumn())->toBe('Preserve this legacy value')
        ->and($fixture->itemName())->toBe('Sample item A');
});

it('applies a nullable column before its dependent index and preserves existing data', function (): void {
    $fixture = $this->acceptance;
    $q = $fixture->quotedSchema;
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items ADD COLUMN note text');
    $fixture->pdo->exec('CREATE INDEX sample_item_note_idx ON '.$q.'.sample_items (note)');
    $desired = postgresAcceptanceSnapshot($fixture);
    $fixture->pdo->exec('DROP INDEX '.$q.'.sample_item_note_idx');
    $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items DROP COLUMN note');
    $comparison = postgresAcceptanceComparison($fixture, $desired);
    expect($comparison['errors'])->toBe([])
        ->and(array_column($comparison['differences'], 'kind'))->toBe(['add_column', 'create_index']);
    $plan = $fixture->align->pin($comparison, ['all-safe']);
    $fixture->align->apply($plan['id'], $plan['signature']);
    expect(postgresAcceptanceComparison($fixture, $desired)['differences'])->toBe([])
        ->and($fixture->itemName())->toBe('Sample item A')
        ->and($fixture->pdo->query('SELECT note FROM '.$q.'.sample_items WHERE id = 1')->fetchColumn())->toBeNull();
    $fixture->pdo->exec("UPDATE {$q}.sample_items SET note = 'Available after upgrade' WHERE id = 1");
    expect($fixture->pdo->query('SELECT note FROM '.$q.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Available after upgrade');
});
