<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;

it('leaves occupied sequence names for manual review while applying independent safe changes', function (string $column, string $sequence, string $collision, bool $ledger): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated PostgreSQL sequence collision tests.');
    }
    $fixture = new ApplyFixture($this->app);

    try {
        $q = $fixture->quotedSchema;
        $owner = $ledger ? 'migrations' : 'sample_groups';
        $fixture->pdo->exec('CREATE TABLE '.$q.'.'.$owner.' (id '.$column.' PRIMARY KEY, migration text NOT NULL, batch integer NOT NULL)');

        if ($sequence !== $owner.'_id_seq') {
            $fixture->pdo->exec('ALTER SEQUENCE '.$q.'.'.$owner.'_id_seq RENAME TO '.$sequence);
        }
        $fixture->pdo->exec("INSERT INTO {$q}.{$owner} (migration, batch) VALUES ('existing_record', 1)");
        $fixture->pdo->exec('CREATE TABLE '.$q.'.widgets (code text)');

        if ($collision === 'rename_index') {
            $fixture->pdo->exec('CREATE INDEX old_widget_index ON '.$q.'.widgets (code)');
        } elseif ($collision === 'rename_constraint') {
            $fixture->pdo->exec('ALTER TABLE '.$q.'.widgets ADD CONSTRAINT old_widget_unique UNIQUE (code)');
        }
        $catalog = new PostgresCatalog($fixture->pdo, [$fixture->schema]);
        $actual = $catalog->inspect();
        // Capture a realizable desired state without changing the occupied live namespace.
        $fixture->pdo->beginTransaction();

        if (str_contains($column, 'IDENTITY')) {
            $fixture->pdo->exec('ALTER TABLE '.$q.'.'.$owner.' ALTER COLUMN id DROP IDENTITY');
        } else {
            $fixture->pdo->exec('ALTER TABLE '.$q.'.'.$owner.' ALTER COLUMN id DROP DEFAULT');
            $fixture->pdo->exec('DROP SEQUENCE '.$q.'.'.$sequence);
        }
        $fixture->pdo->exec(match ($collision) {
            'create_index' => 'CREATE INDEX '.$sequence.' ON '.$q.'.widgets (code)',
            'rename_index' => 'ALTER INDEX '.$q.'.old_widget_index RENAME TO '.$sequence,
            'rename_constraint' => 'ALTER TABLE '.$q.'.widgets RENAME CONSTRAINT old_widget_unique TO '.$sequence,
            'create_table' => 'CREATE TABLE '.$q.'.'.$sequence.' (value text)',
            'add_constraint' => 'CREATE TABLE '.$q.'.new_widgets (code text, CONSTRAINT '.$sequence.' UNIQUE (code))',
        });
        $fixture->pdo->exec('ALTER TABLE '.$q.'.sample_items ADD COLUMN note text');
        $fixture->pdo->exec('CREATE INDEX sample_item_name_safe ON '.$q.'.sample_items (name)');
        $desired = $catalog->inspect();
        $fixture->pdo->rollBack();
        $comparison = (new SchemaComparator)->compare($desired, $actual);
        $comparison['actual'] = $actual->toArray();
        $comparison['desired'] = $desired->toArray();
        $comparison['source'] = ['revision' => 'owned-sequence-collision'];
        $comparison['binding'] = TargetConnection::binding(
            config('database.connections.schema_align_testing'), 'isolated-tests', app()->environment(),
        );
        $plan = $fixture->align->pin($comparison, ['all-safe']);
        $fixture->align->apply($plan['id'], $plan['signature']);
        $fixture->pdo->exec("UPDATE {$q}.sample_items SET note = 'Safe changes committed' WHERE id = 1");
        $fixture->pdo->exec("INSERT INTO {$q}.{$owner} (migration, batch) VALUES ('after_alignment', 2)");

        expect($fixture->pdo->query('SELECT note FROM '.$q.'.sample_items WHERE id = 1')->fetchColumn())
            ->toBe('Safe changes committed')
            ->and($fixture->itemName())->toBe('Sample item A')
            ->and($fixture->pdo->query("SELECT id FROM {$q}.{$owner} WHERE migration = 'after_alignment'")->fetchColumn())->toBe(2);
        $remaining = (new SchemaComparator)->compare($desired, $catalog->inspect());
        foreach ($remaining['differences'] as $difference) {
            expect($difference['automatic'])->toBeFalse();
        }
    } finally {
        $fixture->close();
    }
})->with([
    'serial index creation' => ['serial', 'sample_groups_id_seq', 'create_index', false],
    'renamed identity index creation' => ['integer GENERATED ALWAYS AS IDENTITY', 'custom_sequence', 'create_index', false],
    'renamed serial index rename' => ['serial', 'custom_sequence', 'rename_index', false],
    'identity constraint rename' => ['integer GENERATED ALWAYS AS IDENTITY', 'sample_groups_id_seq', 'rename_constraint', false],
    'serial table creation' => ['serial', 'sample_groups_id_seq', 'create_table', false],
    'identity new table key' => ['integer GENERATED ALWAYS AS IDENTITY', 'sample_groups_id_seq', 'add_constraint', false],
    'migration ledger sequence' => ['serial', 'custom_ledger_sequence', 'create_index', true],
]);
