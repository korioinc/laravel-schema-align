<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Execution\ApplyExecutor;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;

beforeEach(function (): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated PostgreSQL column removal tests.');
    }
    $this->removalFixture = new ApplyFixture($this->app);
});

afterEach(function (): void {
    if (isset($this->removalFixture)) {
        $this->removalFixture->close();
    }
});

/** @return array<string, mixed> */
function columnRemovalComparison(Application $app, ApplyFixture $fixture, Snapshot $desired): array
{
    $actual = (new PostgresCatalog($fixture->pdo, [$fixture->schema]))->inspect();
    $comparison = (new SchemaComparator)->compare($desired, $actual);

    return [...$comparison, 'desired' => $desired->toArray(), 'actual' => $actual->toArray(),
        'source' => ['revision' => 'isolated-column-removal'],
        'binding' => TargetConnection::binding($app->make('config')->get('database.connections.schema_align_testing'), 'isolated-tests', $app->environment())];
}

it('requires column removal permission at the executor and preserves surviving row values', function (string $name): void {
    $f = $this->removalFixture;
    $table = $f->quotedSchema.'.sample_items';
    $column = '"'.str_replace('"', '""', $name).'"';
    $desired = (new PostgresCatalog($f->pdo, [$f->schema]))->inspect();
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' text');
    $f->pdo->exec('UPDATE '.$table.' SET '.$column." = 'Retained until approved' WHERE id = 1");
    $plan = $f->align->pin(columnRemovalComparison($this->app, $f, $desired), ['all-supported']);
    $before = $f->fingerprint();
    $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);
    $executor = new ApplyExecutor($f->pdo, $f->align->store(), [$f->schema]);

    expect(fn () => $executor->apply($plan['id'], $plan['signature'], $plan['binding']))->toThrow(RuntimeException::class)
        ->and($f->fingerprint())->toBe($before)
        ->and(file_get_contents($journalPath))->toBe($journal)
        ->and($f->pdo->query('SELECT '.$column.' FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe('Retained until approved');

    $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' integer');
    $f->pdo->exec('UPDATE '.$table.' SET '.$column.' = 42 WHERE id = 1');

    expect($f->pdo->query('SELECT '.$column.' FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe(42)
        ->and($f->itemName())->toBe('Sample item A');
})->with(['deleted_at', '0', 'old"column']);

it('does not expand an explicit operation selection when column removal is allowed at apply', function (): void {
    $f = $this->removalFixture;
    $table = $f->quotedSchema.'.sample_items';
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN note text');
    $desired = (new PostgresCatalog($f->pdo, [$f->schema]))->inspect();
    $f->pdo->exec('ALTER TABLE '.$table.' DROP COLUMN note');
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN obsolete text');
    $f->pdo->exec("UPDATE {$table} SET obsolete = 'Keep unselected data' WHERE id = 1");
    $comparison = columnRemovalComparison($this->app, $f, $desired);
    $addition = array_values(array_filter($comparison['differences'], static fn (array $change): bool => $change['kind'] === 'add_column' && $change['name'] === 'note'))[0];
    $plan = $f->align->pin($comparison, [$addition['id']]);

    $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true);
    $f->pdo->exec("UPDATE {$table} SET note = 'Safe addition applied' WHERE id = 1");

    expect($f->pdo->query('SELECT obsolete FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe('Keep unselected data')
        ->and($f->pdo->query('SELECT note FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe('Safe addition applied');
});

it('keeps dependent columns outside executable plans', function (string $dependency): void {
    $f = $this->removalFixture;
    $table = $f->quotedSchema.'.sample_items';
    $desired = (new PostgresCatalog($f->pdo, [$f->schema]))->inspect();
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN obsolete integer');
    $f->pdo->exec('UPDATE '.$table.' SET obsolete = 5 WHERE id = 1');
    $f->pdo->exec(str_replace('__TABLE__', $table, $dependency));
    $comparison = columnRemovalComparison($this->app, $f, $desired);
    $removal = array_values(array_filter($comparison['differences'], static fn (array $change): bool => $change['name'] === 'obsolete'))[0];
    $before = $f->fingerprint();

    expect(fn () => $f->align->pin($comparison, [$removal['id']]))->toThrow(RuntimeException::class)
        ->and($f->fingerprint())->toBe($before)
        ->and($f->pdo->query('SELECT obsolete FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe(5);
})->with([
    'index key' => ['CREATE INDEX removal_dependency ON __TABLE__ (obsolete)'],
    'index include' => ['CREATE INDEX removal_dependency ON __TABLE__ (id) INCLUDE (obsolete)'],
    'constraint include' => ['ALTER TABLE __TABLE__ ADD CONSTRAINT removal_dependency UNIQUE (id) INCLUDE (obsolete)'],
    'check constraint' => ['ALTER TABLE __TABLE__ ADD CONSTRAINT removal_dependency CHECK (obsolete > 0)'],
    'generated column' => ['ALTER TABLE __TABLE__ ADD COLUMN computed_value integer GENERATED ALWAYS AS (obsolete + 1) STORED'],
    'expression index' => ['CREATE INDEX removal_dependency ON __TABLE__ ((obsolete + 1))'],
    'predicate index' => ['CREATE INDEX removal_dependency ON __TABLE__ (id) WHERE obsolete > 0'],
]);

it('preserves statistics while applying independent additions and removals', function (string $statisticsColumns): void {
    $f = $this->removalFixture;
    $table = $f->quotedSchema.'.sample_items';
    $other = $f->quotedSchema.'.other_items';
    $statisticsSchema = '"'.$f->schema.'_statistics"';
    $f->pdo->exec('CREATE SCHEMA '.$statisticsSchema);

    try {
        $f->pdo->exec('CREATE TABLE '.$other.' (id integer PRIMARY KEY, name text)');
        $f->pdo->exec("INSERT INTO {$other} VALUES (1, 'Other row')");
        $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN note text');
        $desired = (new PostgresCatalog($f->pdo, [$f->schema]))->inspect();
        $f->pdo->exec('ALTER TABLE '.$table.' DROP COLUMN note');
        foreach ([$table, $other] as $target) {
            $f->pdo->exec('ALTER TABLE '.$target.' ADD COLUMN obsolete integer');
            $f->pdo->exec('UPDATE '.$target.' SET obsolete = 5 WHERE id = 1');
        }
        $f->pdo->exec('CREATE STATISTICS '.$statisticsSchema.'.removal_dependency ON '.$statisticsColumns.' FROM '.$table);
        $statisticsQuery = 'SELECT pg_get_statisticsobjdef(s.oid) FROM pg_statistic_ext s JOIN pg_namespace n ON n.oid = s.stxnamespace WHERE n.nspname = '.$f->pdo->quote($f->schema.'_statistics')." AND s.stxname = 'removal_dependency'";
        $statistics = $f->pdo->query($statisticsQuery)->fetchColumn();
        $plan = $f->align->pin(columnRemovalComparison($this->app, $f, $desired), ['all-supported']);

        $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true);
        $f->pdo->exec("UPDATE {$table} SET note = 'Independent addition' WHERE id = 1");
        $f->pdo->exec('ALTER TABLE '.$other.' ADD COLUMN obsolete text');

        expect($f->pdo->query('SELECT obsolete FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe(5)
            ->and($f->pdo->query('SELECT note FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe('Independent addition')
            ->and($f->pdo->query('SELECT name FROM '.$other.' WHERE id = 1')->fetchColumn())->toBe('Other row')
            ->and($f->pdo->query($statisticsQuery)->fetchColumn())->toBe($statistics);
    } finally {
        $f->pdo->exec('DROP SCHEMA '.$statisticsSchema.' CASCADE');
    }
})->with(['id, obsolete', '(obsolete + 1)']);

it('refuses a removal plan after a dependency is added', function (string $dependency): void {
    $f = $this->removalFixture;
    $table = $f->quotedSchema.'.sample_items';
    $desired = (new PostgresCatalog($f->pdo, [$f->schema]))->inspect();
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN obsolete integer');
    $f->pdo->exec('UPDATE '.$table.' SET obsolete = 5 WHERE id = 1');
    $plan = $f->align->pin(columnRemovalComparison($this->app, $f, $desired), ['all-supported']);
    $f->pdo->exec(str_replace(['__TABLE__', '__SCHEMA__'], [$table, $f->quotedSchema], $dependency));
    $before = $f->fingerprint();

    expect(fn () => $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true))->toThrow(RuntimeException::class)
        ->and($f->fingerprint())->toBe($before)
        ->and($f->pdo->query('SELECT obsolete FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe(5);
})->with([
    'index' => ['CREATE INDEX removal_dependency ON __TABLE__ (obsolete)'],
    'statistics' => ['CREATE STATISTICS __SCHEMA__.removal_dependency ON id, obsolete FROM __TABLE__'],
]);

it('preserves dependent views outside the inspected schemas', function (): void {
    $f = $this->removalFixture;
    $table = $f->quotedSchema.'.sample_items';
    $outside = '"'.$f->schema.'_outside"';
    $desired = (new PostgresCatalog($f->pdo, [$f->schema]))->inspect();
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN obsolete integer');
    $f->pdo->exec('UPDATE '.$table.' SET obsolete = 5 WHERE id = 1');
    $f->pdo->exec('CREATE SCHEMA '.$outside);

    try {
        $f->pdo->exec('CREATE VIEW '.$outside.'.consumer AS SELECT obsolete FROM '.$table);
        $plan = $f->align->pin(columnRemovalComparison($this->app, $f, $desired), ['all-supported']);
        $before = $f->fingerprint();

        expect(fn () => $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true))->toThrow(PDOException::class)
            ->and($f->fingerprint())->toBe($before)
            ->and($f->pdo->query('SELECT obsolete FROM '.$outside.'.consumer')->fetchColumn())->toBe(5)
            ->and($f->itemName())->toBe('Sample item A');
    } finally {
        $f->pdo->exec('DROP SCHEMA '.$outside.' CASCADE');
    }
});

it('rolls back removed column data when a later statement fails', function (): void {
    $f = $this->removalFixture;
    $table = $f->quotedSchema.'.sample_items';
    $desired = (new PostgresCatalog($f->pdo, [$f->schema]))->inspect();
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN obsolete text');
    $f->pdo->exec("UPDATE {$table} SET obsolete = 'Restore on rollback' WHERE id = 1");
    $plan = $f->align->pin(columnRemovalComparison($this->app, $f, $desired), ['all-supported']);
    $plan['operations'][0]['sql'][] = 'SELECT 1 / 0';
    unset($plan['signature']);
    $plan = $f->align->store()->save($plan);
    $before = $f->fingerprint();

    expect(fn () => $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true))->toThrow(PDOException::class)
        ->and($f->fingerprint())->toBe($before)
        ->and($f->pdo->query('SELECT obsolete FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe('Restore on rollback')
        ->and($f->itemName())->toBe('Sample item A');
});
