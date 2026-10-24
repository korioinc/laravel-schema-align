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
