<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;

it('preserves PostgreSQL connection search paths through planning reconnects and apply', function (string $configuration): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable PostgreSQL app tests are required.');
    }
    $fixture = new InstalledAppFixture($this->app);
    $reportingSchema = $fixture->schema.'_reporting';
    $quotedReporting = '"'.$reportingSchema.'"';

    try {
        $fixture->pdo->exec('CREATE SCHEMA '.$quotedReporting);
        $fixture->pdo->exec('CREATE TABLE '.$quotedReporting.'.reports (id integer NOT NULL, name varchar(255) NOT NULL)');
        $fixture->pdo->exec("INSERT INTO {$quotedReporting}.reports VALUES (7, 'Existing report')");
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.installed_target');
        $schemas = [$fixture->schema, $reportingSchema];

        if ($configuration === 'legacy schema') {
            unset($connection['search_path']);
            $connection['schema'] = '"'.$reportingSchema.'", "'.$fixture->schema.'"';
            $schemas = array_reverse($schemas);
        } else {
            $connection['search_path'] = $configuration === 'array'
                ? [$reportingSchema, $fixture->schema]
                : $reportingSchema;
        }
        $config->set('schema-align.schemas', $schemas);
        $config->set('database.connections.reporting', $connection);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_reporting.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::connection('reporting')->create('reports', function ($table): void {
            $table->integer('id');
            $table->string('name');
        });
        if (! \Illuminate\Support\Facades\Schema::connection('reporting')->hasTable('reports')) {
            throw new RuntimeException('The reporting search path was not preserved for schema inspection.');
        }
        \Illuminate\Support\Facades\DB::purge('reporting');
        \Illuminate\Support\Facades\DB::connection('reporting')->table('reports')->insert(['id' => 1, 'name' => 'Replay report']);
        $dynamic = \Illuminate\Support\Facades\DB::build(config('database.connections.reporting'));
        $dynamic->getSchemaBuilder()->create('batches', function ($table): void {
            $table->integer('id');
        });
        $dynamic->table('batches')->insert(['id' => 1]);
    }
};
PHP);
        $catalog = new PostgresCatalog($fixture->pdo, $schemas);
        $before = $catalog->inspect()->fingerprint();
        $plan = $fixture->align->plan(['all-supported']);
        $reportOperations = array_filter($plan['operations'], static fn (array $operation): bool => str_ends_with($operation['table'], '.reports'));

        expect($reportOperations)->toBeEmpty()
            ->and($catalog->inspect()->fingerprint())->toBe($before)
            ->and($fixture->pdo->query('SELECT name FROM '.$quotedReporting.'.reports')->fetchAll(PDO::FETCH_COLUMN))->toBe(['Existing report']);

        $fixture->align->apply($plan['id'], $plan['signature']);

        expect($fixture->align->diff()['differences'])->toBe([])
            ->and($fixture->pdo->query('SELECT name FROM '.$quotedReporting.'.reports')->fetchAll(PDO::FETCH_COLUMN))->toBe(['Existing report'])
            ->and((int) $fixture->pdo->query('SELECT COUNT(*) FROM '.$quotedReporting.'.batches')->fetchColumn())->toBe(0)
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->pdo->exec('DROP SCHEMA IF EXISTS '.$quotedReporting.' CASCADE');
        $fixture->close();
    }
})->with(['string', 'array', 'legacy schema']);

it('rejects PostgreSQL connection search paths outside the replay scope without changing target data', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable PostgreSQL app tests are required.');
    }
    $fixture = new InstalledAppFixture($this->app);
    $outsideSchema = $fixture->schema.'_outside';
    $quotedOutside = '"'.$outsideSchema.'"';

    try {
        $fixture->pdo->exec('CREATE SCHEMA '.$quotedOutside);
        $fixture->pdo->exec('CREATE TABLE '.$quotedOutside.'.reports (id integer NOT NULL, name varchar(255) NOT NULL)');
        $fixture->pdo->exec("INSERT INTO {$quotedOutside}.reports VALUES (7, 'Outside report')");
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.installed_target');
        $connection['search_path'] = $outsideSchema;
        $config->set('database.connections.reporting', $connection);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_reporting.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::connection('reporting')->create('new_reports', function ($table): void {
            $table->integer('id');
        });
    }
};
PHP);
        $catalog = new PostgresCatalog($fixture->pdo, [$fixture->schema, $outsideSchema]);
        $before = $catalog->inspect()->fingerprint();

        expect(fn () => $fixture->align->plan(['all-supported']))->toThrow(RuntimeException::class)
            ->and($catalog->inspect()->fingerprint())->toBe($before)
            ->and($fixture->pdo->query('SELECT name FROM '.$quotedOutside.'.reports')->fetchAll(PDO::FETCH_COLUMN))->toBe(['Outside report'])
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->pdo->exec('DROP SCHEMA IF EXISTS '.$quotedOutside.' CASCADE');
        $fixture->close();
    }
});
