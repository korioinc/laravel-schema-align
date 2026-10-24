<?php

declare(strict_types=1);

use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\ConfigurationUrlParser;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

it('preserves PostgreSQL alias timezones when replaying literal timestamp defaults', function (string $configuration): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable PostgreSQL app tests are required.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.installed_target');
        $primaryTimezone = in_array($configuration, ['absent', 'null'], true) ? 'Asia/Seoul' : 'UTC';
        $config->set('database.connections.installed_target.timezone', $primaryTimezone);

        if ($configuration === 'explicit') {
            $connection['timezone'] = 'Asia/Seoul';
            $options = [PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_CASE => PDO::CASE_NATURAL];
            $config->set('database.connections.installed_target.options', $options);
            $connection['options'] = array_reverse($options, true);
        } elseif ($configuration === 'url') {
            $connection['timezone'] = 'UTC';
            $connection['url'] = 'pgsql://'.rawurlencode($connection['username']).':'.rawurlencode($connection['password'] ?? '')
                .'@'.$connection['host'].':'.$connection['port'].'/'.$connection['database'].'?timezone=Asia%2FSeoul';
        } elseif ($configuration === 'null') {
            $connection['timezone'] = null;
        } else {
            unset($connection['timezone']);
        }
        $config->set('database.connections.reporting', $connection);

        // Establish the existing schema with Laravel's ordinary connector, independently of replay.
        $actualPdo = (new PostgresConnector)->connect((new ConfigurationUrlParser)->parseConfiguration($connection));
        expect($actualPdo->query('SHOW TIMEZONE')->fetchColumn())->not->toBe($primaryTimezone);
        $tables = ['session_named', 'session_reconnected', 'session_dynamic'];
        $rows = [];

        foreach ($tables as $table) {
            $qualified = $fixture->quotedSchema.'.'.$table;
            $actualPdo->exec('CREATE TABLE '.$qualified." (id integer NOT NULL, occurred_at timestamp(0) with time zone NOT NULL DEFAULT '2026-01-01 00:00:00')");
            $actualPdo->exec('INSERT INTO '.$qualified.' (id) VALUES (7)');
            $rows[$table] = $fixture->pdo->query('SELECT id, occurred_at FROM '.$qualified)->fetchAll(PDO::FETCH_ASSOC);
        }

        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_session_settings.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        $columns = static function ($table): void {
            $table->integer('id');
            $table->timestampTz('occurred_at', 0)->default('2026-01-01 00:00:00');
        };
        \Illuminate\Support\Facades\Schema::connection('reporting')->create('session_named', $columns);
        \Illuminate\Support\Facades\DB::purge('reporting');
        \Illuminate\Support\Facades\DB::connection('reporting')->getSchemaBuilder()->create('session_reconnected', $columns);
        \Illuminate\Support\Facades\DB::build(config('database.connections.reporting'))->getSchemaBuilder()->create('session_dynamic', $columns);
    }
};
PHP);
        $before = $fixture->fingerprint();
        $comparison = $fixture->align->diff();

        foreach ($tables as $table) {
            $key = $fixture->schema.'.'.$table;
            $differences = array_filter($comparison['differences'], static fn (array $difference): bool => $difference['table'] === $key);
            expect($comparison['desired']['tables'][$key]['columns']['occurred_at']['default'])
                ->toBe($comparison['actual']['tables'][$key]['columns']['occurred_at']['default'])
                ->and($differences)->toBeEmpty();
        }

        $plan = $fixture->align->plan(['all-safe']);
        foreach ($tables as $table) {
            expect(array_column($plan['operations'], 'table'))->not->toContain($fixture->schema.'.'.$table)
                ->and($fixture->pdo->query('SELECT id, occurred_at FROM '.$fixture->quotedSchema.'.'.$table)->fetchAll(PDO::FETCH_ASSOC))->toBe($rows[$table]);
        }
        expect($fixture->fingerprint())->toBe($before)
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
})->with(['explicit', 'url', 'absent', 'null']);

it('preserves MySQL alias session settings through replay reconnects and dynamic connections', function (): void {
    if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable MySQL app tests are required.');
    }
    $fixture = new MySqlInstalledFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.consumer_mysql');
        $config->set('database.connections.consumer_mysql', array_replace($connection, [
            'timezone' => '+00:00', 'isolation_level' => 'REPEATABLE READ', 'modes' => ['STRICT_TRANS_TABLES'],
        ]));
        $connection = array_replace($connection, [
            'charset' => 'latin1', 'collation' => 'latin1_bin', 'timezone' => '+09:00',
            'isolation_level' => 'READ COMMITTED', 'modes' => ['ANSI_QUOTES'],
        ]);
        $config->set('database.connections.reporting', $connection);
        $actualPdo = (new MySqlConnector)->connect($connection);
        $query = 'SELECT @@SESSION.time_zone AS timezone, @@SESSION.character_set_client AS charset, @@SESSION.collation_connection AS collation, @@SESSION.sql_mode AS modes, @@SESSION.transaction_isolation AS isolation_level';
        $expected = ['timezone' => '+09:00', 'charset' => 'latin1', 'collation' => 'latin1_bin', 'modes' => 'ANSI_QUOTES', 'isolation_level' => 'READ-COMMITTED'];
        expect($actualPdo->query($query)->fetch(PDO::FETCH_ASSOC))->toBe($expected);

        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_session_settings.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        $create = static function ($connection, string $table): void {
            $settings = $connection->selectOne('SELECT @@SESSION.time_zone AS timezone, @@SESSION.character_set_client AS charset, @@SESSION.collation_connection AS collation, @@SESSION.sql_mode AS modes, @@SESSION.transaction_isolation AS isolation_level');
            if ((array) $settings !== ['timezone' => '+09:00', 'charset' => 'latin1', 'collation' => 'latin1_bin', 'modes' => 'ANSI_QUOTES', 'isolation_level' => 'READ-COMMITTED']) {
                throw new RuntimeException('Replay changed connection-local MySQL session settings.');
            }
            $connection->getSchemaBuilder()->create($table, static function ($table): void {
                $table->integer('id');
            });
        };
        $create(\Illuminate\Support\Facades\DB::connection('reporting'), 'session_named');
        \Illuminate\Support\Facades\DB::purge('reporting');
        $create(\Illuminate\Support\Facades\DB::connection('reporting'), 'session_reconnected');
        $create(\Illuminate\Support\Facades\DB::build(config('database.connections.reporting')), 'session_dynamic');
    }
};
PHP);
        $before = $fixture->fingerprint();
        $comparison = $fixture->align->diff();
        $plan = $fixture->align->plan(['all-safe']);

        foreach (['session_named', 'session_reconnected', 'session_dynamic'] as $table) {
            expect($comparison['desired']['tables'])->toHaveKey('@database.'.$table)
                ->and(array_column($plan['operations'], 'table'))->toContain('@database.'.$table);
        }
        expect($fixture->fingerprint())->toBe($before)
            ->and($fixture->itemName())->toBe('Sample item A');
    } finally {
        $fixture->pdo->exec('DROP TABLE IF EXISTS session_named, session_reconnected, session_dynamic');
        $fixture->close();
    }
});

it('rejects unsupported auxiliary startup settings before replay planning', function (string $driver, string $setting): void {
    if (getenv($driver === 'pgsql' ? 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS' : 'SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable database app tests are required.');
    }
    $fixture = $driver === 'pgsql' ? new InstalledAppFixture($this->app) : new MySqlInstalledFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.'.$config->get('database.default'));
        $connection[$setting] = $setting === 'options' ? [PDO::ATTR_CASE => PDO::CASE_LOWER] : ['timezone' => 'Asia/Seoul'];
        $config->set('database.connections.reporting', $connection);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_session_settings.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\DB::connection('reporting')->getPdo();
    }
};
PHP);
        $before = $fixture->fingerprint();

        expect(fn () => $fixture->align->plan(['all-safe']))->toThrow(RuntimeException::class, 'unsupported_replay_session_config')
            ->and($fixture->fingerprint())->toBe($before)
            ->and($driver === 'pgsql' ? $fixture->recordName() : $fixture->itemName())
            ->toBe($driver === 'pgsql' ? 'Sample record A' : 'Sample item A');
    } finally {
        $fixture->close();
    }
})->with([['pgsql', 'options'], ['pgsql', 'server_options'], ['mysql', 'options']]);
