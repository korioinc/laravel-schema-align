<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\MySqlComparator;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;
use Korioinc\SchemaAlign\SchemaAlign;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;
use Korioinc\SchemaAlign\Tests\Support\MySqlFixture;

/** @return array<string, mixed> */
function numericPostgresComparison(ApplyFixture $fixture, Snapshot $desired): array
{
    $actual = (new PostgresCatalog($fixture->pdo, [$fixture->schema]))->inspect();

    return [...(new SchemaComparator)->compare($desired, $actual), 'actual' => $actual->toArray(),
        'source' => ['revision' => 'numeric-identifiers'],
        'binding' => TargetConnection::binding(config('database.connections.schema_align_testing'), 'isolated-tests', app()->environment())];
}

it('pins and applies safe PostgreSQL changes while retaining numeric SQL identifiers', function (string $name): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated numeric identifier tests.');
    }
    $f = new ApplyFixture($this->app);

    try {
        $f->pdo->exec('ALTER TABLE '.$f->quotedSchema.'.sample_items ADD COLUMN "'.$name.'" text');
        $f->pdo->exec('UPDATE '.$f->quotedSchema.'.sample_items SET "'.$name.'"=\'Retained numeric column\' WHERE id=1');
        $f->pdo->exec('CREATE INDEX "'.$name.'" ON '.$f->quotedSchema.'.sample_items ("'.$name.'")');
        $plan = $f->plan();
        $f->align->apply($plan['id'], $plan['signature']);
        $f->pdo->exec('UPDATE '.$f->quotedSchema.'.sample_items SET note=\'Safe addition\' WHERE id=1');

        expect($f->pdo->query('SELECT "'.$name.'" FROM '.$f->quotedSchema.'.sample_items WHERE id=1')->fetchColumn())->toBe('Retained numeric column')
            ->and($f->pdo->query('SELECT note FROM '.$f->quotedSchema.'.sample_items WHERE id=1')->fetchColumn())->toBe('Safe addition')
            ->and($f->itemName())->toBe('Sample item A');
    } finally {
        $f->close();
    }
})->with(['2025', '0', '-1', '01']);

it('creates PostgreSQL numeric columns and constraints and renames numeric indexes', function (): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated numeric identifier tests.');
    }
    $f = new ApplyFixture($this->app);

    try {
        $f->pdo->exec('CREATE TABLE '.$f->quotedSchema.'.numeric_names ("2025" text CONSTRAINT "0" NOT NULL, "0" integer CONSTRAINT "1" PRIMARY KEY)');
        $f->pdo->exec('CREATE INDEX "2026" ON '.$f->quotedSchema.'.sample_items (name)');
        $catalog = new PostgresCatalog($f->pdo, [$f->schema]);
        $desired = $catalog->inspect();
        $f->pdo->exec('DROP TABLE '.$f->quotedSchema.'.numeric_names');
        $f->pdo->exec('ALTER INDEX '.$f->quotedSchema.'."2026" RENAME TO "2025"');
        $plan = $f->align->pin(numericPostgresComparison($f, $desired), ['all-supported']);
        $f->align->apply($plan['id'], $plan['signature']);
        $f->pdo->exec('INSERT INTO '.$f->quotedSchema.'.numeric_names ("2025", "0") VALUES (\'Numeric names work\', 1)');

        expect($catalog->inspect()->fingerprint())->toBe($desired->fingerprint())
            ->and($f->pdo->query('SELECT "2025" FROM '.$f->quotedSchema.'.numeric_names WHERE "0"=1')->fetchColumn())->toBe('Numeric names work')
            ->and($f->itemName())->toBe('Sample item A');
        expect(fn () => $f->pdo->exec('INSERT INTO '.$f->quotedSchema.'.numeric_names ("2025", "0") VALUES (NULL, 2)'))->toThrow(PDOException::class);
    } finally {
        $f->close();
    }
});

it('keeps numeric column fingerprints independent of physical column order', function (): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated numeric identifier tests.');
    }
    $f = new ApplyFixture($this->app);

    try {
        $columns = array_map(fn (int $name): string => '"'.$name.'" text', range(0, 10));
        $create = 'CREATE TABLE '.$f->quotedSchema.'.numeric_order (';
        $f->pdo->exec($create.implode(', ', $columns).')');
        $catalog = new PostgresCatalog($f->pdo, [$f->schema]);
        $before = $catalog->inspect();
        $f->pdo->exec('DROP TABLE '.$f->quotedSchema.'.numeric_order');
        $f->pdo->exec($create.implode(', ', array_reverse($columns)).')');

        expect($catalog->inspect()->fingerprint())->toBe($before->fingerprint())
            ->and(Snapshot::fromArray(json_decode(json_encode($before->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))->fingerprint())->toBe($before->fingerprint());
    } finally {
        $f->close();
    }
});

it('pins and applies MySQL additions and index renames with numeric identifiers', function (): void {
    if (! getenv('SCHEMA_ALIGN_MYSQL_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_DSN for isolated numeric identifier tests.');
    }
    $f = new MySqlFixture;
    $this->app->make('config')->set('database.connections.numeric_identifiers', ['driver' => 'mysql']);
    $this->app->make('config')->set('schema-align.connection', 'numeric_identifiers');
    $this->app->make('config')->set('schema-align.plan_directory', $f->directory);
    $align = new SchemaAlign($this->app);

    try {
        $f->pdo->exec('ALTER TABLE `'.$f->table.'` ADD COLUMN `2025` text NULL');
        $f->pdo->exec('UPDATE `'.$f->table.'` SET `2025`=\'Retained numeric column\' WHERE id=1');
        $f->pdo->exec('ALTER TABLE `'.$f->table.'` ADD COLUMN `0` text NULL');
        $f->pdo->exec('CREATE INDEX `2026` ON `'.$f->table.'` (name)');
        $desired = $f->inspect();
        $f->pdo->exec('ALTER TABLE `'.$f->table.'` DROP COLUMN `0`');
        $f->pdo->exec('ALTER TABLE `'.$f->table.'` RENAME INDEX `2026` TO `2025`');
        $actual = $f->inspect();
        $comparison = [...(new MySqlComparator)->compare($desired, $actual), 'actual' => $actual->toArray(),
            'binding' => $f->binding, 'source' => ['revision' => 'numeric-identifiers']];
        $plan = $align->pin($comparison, ['all-supported']);
        $f->apply($plan);
        $f->pdo->exec('SET SESSION transaction_read_only = OFF');
        $f->pdo->exec('UPDATE `'.$f->table.'` SET `0`=\'Safe addition\' WHERE id=1');

        expect($f->pdo->query('SELECT `2025` FROM `'.$f->table.'` WHERE id=1')->fetchColumn())->toBe('Retained numeric column')
            ->and($f->pdo->query('SELECT `0` FROM `'.$f->table.'` FORCE INDEX (`2026`) WHERE name=\'Sample item A\'')->fetchColumn())->toBe('Safe addition');
    } finally {
        $f->close();
    }
});
