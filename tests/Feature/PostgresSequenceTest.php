<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;

beforeEach(function (): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN/USER/PASSWORD for the isolated PostgreSQL sequence target.');
    }
    $this->sequenceFixture = new ApplyFixture($this->app);
});

afterEach(function (): void {
    if (isset($this->sequenceFixture)) {
        $this->sequenceFixture->close();
    }
});

it('applies a schema change with standard matching owned sequence types', function (string $column): void {
    $fixture = $this->sequenceFixture;
    $fixture->pdo->exec('CREATE TABLE '.$fixture->quotedSchema.'.sequence_values (id '.$column.' PRIMARY KEY)');
    $plan = $fixture->plan();

    $fixture->align->apply($plan['id'], $plan['signature']);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_items SET note = 'Available after upgrade' WHERE id = 1");

    expect($fixture->pdo->query('SELECT note FROM '.$fixture->quotedSchema.'.sample_items WHERE id = 1')->fetchColumn())
        ->toBe('Available after upgrade')
        ->and($fixture->itemName())->toBe('Sample item A');
})->with([
    'smallserial' => ['smallserial'],
    'serial' => ['serial'],
    'bigserial' => ['bigserial'],
    'smallint identity' => ['smallint GENERATED ALWAYS AS IDENTITY'],
    'integer identity' => ['integer GENERATED ALWAYS AS IDENTITY'],
    'bigint identity' => ['bigint GENERATED ALWAYS AS IDENTITY'],
]);

it('preserves legacy serial sequence bounds while applying unrelated schema changes', function (string $column, int $maximum): void {
    $fixture = $this->sequenceFixture;
    $table = $fixture->quotedSchema.'.sequence_values';
    $sequence = $table.'_id_seq';
    $fixture->pdo->exec('CREATE TABLE '.$table.' (id '.$column.' PRIMARY KEY)');
    $fixture->pdo->exec('ALTER SEQUENCE '.$sequence.' AS bigint MAXVALUE '.$maximum);
    $fixture->pdo->query('SELECT setval('.$fixture->pdo->quote($sequence).'::regclass, '.($maximum - 1).', true)');
    $plan = $fixture->plan();

    $fixture->align->apply($plan['id'], $plan['signature']);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_items SET note = 'Aligned' WHERE id = 1");

    expect($fixture->pdo->query('SELECT note FROM '.$fixture->quotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Aligned')
        ->and($fixture->pdo->query('INSERT INTO '.$table.' DEFAULT VALUES RETURNING id')->fetchColumn())->toBe($maximum);
    expect(fn () => $fixture->pdo->query('SELECT nextval('.$fixture->pdo->quote($sequence).'::regclass)'))->toThrow(PDOException::class);
})->with([
    'smallserial with bigint storage' => ['smallserial', 32767],
    'serial with bigint storage' => ['serial', 2147483647],
]);

it('refuses schema changes when a widened sequence is not a bounded owned serial', function (string $column, ?string $alteration): void {
    $fixture = $this->sequenceFixture;
    $table = $fixture->quotedSchema.'.sequence_values';
    $fixture->pdo->exec('CREATE TABLE '.$table.' (id '.$column.' PRIMARY KEY)');
    $fixture->pdo->exec('ALTER SEQUENCE '.$table.'_id_seq AS bigint MAXVALUE 2147483647');

    if ($alteration !== null) {
        $fixture->pdo->exec(str_replace('__TABLE__', $table, $alteration));
    }

    expect(fn () => $fixture->plan())->toThrow(RuntimeException::class)
        ->and($fixture->itemName())->toBe('Sample item A');
})->with([
    'identity type mismatch' => ['integer GENERATED ALWAYS AS IDENTITY', null],
    'oversized range' => ['serial', 'ALTER SEQUENCE __TABLE___id_seq NO MAXVALUE'],
    'custom range' => ['serial', 'ALTER SEQUENCE __TABLE___id_seq MAXVALUE 1000'],
    'missing ownership' => ['serial', 'ALTER SEQUENCE __TABLE___id_seq OWNED BY NONE'],
    'missing default' => ['serial', 'ALTER TABLE __TABLE__ ALTER COLUMN id DROP DEFAULT'],
]);

it('invalidates a pinned plan after owned sequence type or range drift', function (string $column, string $alteration): void {
    $fixture = $this->sequenceFixture;
    $fixture->pdo->exec('CREATE TABLE '.$fixture->quotedSchema.'.sequence_values (id '.$column.' PRIMARY KEY)');
    $plan = $fixture->plan();
    $baseline = $fixture->fingerprint();

    $fixture->pdo->exec('ALTER SEQUENCE '.$fixture->quotedSchema.'.sequence_values_id_seq '.$alteration);
    $divergent = $fixture->fingerprint();

    expect($divergent)->not->toBe($baseline);
    expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($divergent)
        ->and($fixture->itemName())->toBe('Sample item A');
})->with([
    'bigserial type' => ['bigserial', 'AS smallint'],
    'bigint identity type' => ['bigint GENERATED ALWAYS AS IDENTITY', 'AS smallint'],
    'bigserial maximum' => ['bigserial', 'MAXVALUE 1000'],
    'bigint identity maximum' => ['bigint GENERATED ALWAYS AS IDENTITY', 'MAXVALUE 1000'],
    'bigserial minimum' => ['bigserial', 'MINVALUE 0'],
    'bigint identity minimum' => ['bigint GENERATED ALWAYS AS IDENTITY', 'MINVALUE 0'],
]);
