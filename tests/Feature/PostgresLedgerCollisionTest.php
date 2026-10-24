<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;

it('invalidates a pending plan when an excluded ledger index changes its occupied name', function (): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated PostgreSQL ledger collision tests.');
    }
    $fixture = new ApplyFixture($this->app);

    try {
        $q = $fixture->quotedSchema;
        $fixture->pdo->exec('CREATE TABLE '.$q.'.migrations (id serial PRIMARY KEY, migration text NOT NULL, batch integer NOT NULL)');
        $plan = $fixture->plan();
        $before = $fixture->fingerprint();
        $fixture->pdo->exec('ALTER INDEX '.$q.'.migrations_pkey RENAME TO reserved_by_ledger');
        $actual = (new PostgresCatalog($fixture->pdo, [$fixture->schema]))->inspect();

        expect($actual->fingerprint())->not->toBe($before)
            ->and($actual->tables)->not->toHaveKey($fixture->schema.'.migrations')
            ->and($actual->platform['excluded_relations'])->toHaveKeys([$fixture->schema.'.migrations', $fixture->schema.'.reserved_by_ledger'])
            ->and(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class, 'divergent')
            ->and($fixture->fingerprint())->toBe($actual->fingerprint())
            ->and($fixture->itemName())->toBe('Sample item A');
    } finally {
        $fixture->close();
    }
});

it('keeps names occupied by excluded migration ledger indexes out of automatic plans', function (string $ledger, bool $primary, string $kind): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated PostgreSQL ledger collision tests.');
    }
    $fixture = new ApplyFixture($this->app);

    try {
        config()->set('schema-align.engines.pgsql.migration_table', $ledger);
        config()->set('schema-align.migration_table', $ledger);
        $q = $fixture->quotedSchema;
        $fixture->pdo->exec('CREATE TABLE '.$q.'.'.$ledger.' (id serial PRIMARY KEY, migration text NOT NULL, batch integer NOT NULL)');
        $fixture->pdo->exec("INSERT INTO {$q}.{$ledger} (migration, batch) VALUES ('existing_migration', 1)");
        $fixture->pdo->exec($primary
            ? 'ALTER INDEX '.$q.'.'.$ledger.'_pkey RENAME TO sample_values_idx'
            : 'CREATE INDEX sample_values_idx ON '.$q.'.'.$ledger.' (migration)');

        if ($kind === 'rename_index') {
            $fixture->pdo->exec('CREATE INDEX old_name ON '.$q.'.sample_items (name)');
        } elseif ($kind === 'rename_constraint') {
            $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items ADD CONSTRAINT old_name UNIQUE (name)');
        }
        $catalog = new PostgresCatalog($fixture->pdo, [$fixture->schema], $ledger);
        $actual = $catalog->inspect();
        // Construct a realizable desired state, then restore the occupied original namespace.
        $fixture->pdo->beginTransaction();
        $fixture->pdo->exec('ALTER INDEX '.$q.'.sample_values_idx RENAME TO available_ledger_index');
        $fixture->pdo->exec(match ($kind) {
            'create_index' => 'CREATE INDEX sample_values_idx ON '.$q.'.sample_items (name)',
            'rename_index' => 'ALTER INDEX '.$q.'.old_name RENAME TO sample_values_idx',
            'rename_constraint' => 'ALTER TABLE '.$q.'.sample_items RENAME CONSTRAINT old_name TO sample_values_idx',
            'create_table' => 'CREATE TABLE '.$q.'.sample_values_idx (value text)',
            'add_constraint' => 'CREATE TABLE '.$q.'.new_widgets (name text, CONSTRAINT sample_values_idx UNIQUE (name))',
        });
        $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items ADD COLUMN note text');
        $fixture->pdo->exec('CREATE INDEX safe_name_index ON '.$q.'.sample_items (note)');
        $desired = $catalog->inspect();
        $fixture->pdo->rollBack();
        $comparison = (new SchemaComparator)->compare($desired, $actual);
        $collision = array_values(array_filter($comparison['differences'], static fn (array $operation): bool => $operation['name'] === 'sample_values_idx'));

        expect($comparison['errors'])->toBe([])
            ->and($actual->tables)->not->toHaveKey($fixture->schema.'.'.$ledger)
            ->and($collision)->toHaveCount(1)
            ->and($collision[0]['automatic'])->toBeFalse()
            ->and($collision[0]['sql'])->toBe([]);
        $comparison['actual'] = $actual->toArray();
        $comparison['desired'] = $desired->toArray();
        $comparison['source'] = ['revision' => 'ledger-collision'];
        $comparison['binding'] = TargetConnection::binding(config('database.connections.schema_align_testing'), 'isolated-tests', app()->environment());
        $plan = $fixture->align->pin($comparison, ['all-safe']);
        expect(array_column($plan['operations'], 'name'))->toContain('note', 'safe_name_index')->not->toContain('sample_values_idx');
        $fixture->align->apply($plan['id'], $plan['signature']);
        $fixture->pdo->exec("UPDATE {$q}.sample_items SET note = 'Safe changes committed' WHERE id = 1");

        expect($fixture->pdo->query('SELECT note FROM '.$q.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Safe changes committed')
            ->and($fixture->itemName())->toBe('Sample item A')
            ->and($catalog->inspect()->migrationNames)->toBe(['existing_migration'])
            ->and($fixture->pdo->query('SELECT i.indrelid::regclass::text FROM pg_index i WHERE i.indexrelid = '.$fixture->pdo->quote($q.'.sample_values_idx').'::regclass')->fetchColumn())->toBe($fixture->schema.'.'.$ledger);
    } finally {
        $fixture->close();
    }
})->with([
    'primary index creation' => ['migrations', true, 'create_index'],
    'custom ledger primary index' => ['schema_history', true, 'create_index'],
    'standalone ledger index' => ['migrations', false, 'create_index'],
    'index rename' => ['migrations', true, 'rename_index'],
    'constraint rename' => ['migrations', true, 'rename_constraint'],
    'new table' => ['migrations', true, 'create_table'],
    'new constraint index' => ['migrations', true, 'add_constraint'],
]);
