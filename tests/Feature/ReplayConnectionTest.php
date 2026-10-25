<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

it('preserves auxiliary connection prefixes through replay planning and apply', function (string $driver, bool $url): void {
    if (getenv($driver === 'pgsql' ? 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS' : 'SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable database app tests are required.');
    }
    $fixture = $driver === 'pgsql' ? new InstalledAppFixture($this->app) : new MySqlInstalledFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $selected = 'database.connections.'.$config->get('database.default');
        $connection = $config->get($selected);
        $config->set($selected.'.prefix_indexes', $url);
        $connection['prefix'] = 'report_';

        if ($url) {
            // URL values must override the array, and an absent prefix_indexes must stay absent.
            $connection['url'] = $driver.'://'.rawurlencode($connection['username']).':'.rawurlencode($connection['password'] ?? '')
                .'@'.$connection['host'].':'.$connection['port'].'/'.$connection['database'].'?prefix=report_';
            $connection['prefix'] = 'ignored_';
            unset($connection['prefix_indexes']);
        } else {
            $connection['prefix_indexes'] = true;
        }

        if ($driver === 'mysql') {
            $connection['charset'] = 'latin1';
            $connection['collation'] = 'latin1_bin';
            $connection['engine'] = 'InnoDB';
        }
        $config->set('database.connections.reporting', $connection);
        $reporting = $fixture->app->make('db')->connection('reporting');
        $reporting->getSchemaBuilder()->create('events', function (Blueprint $table): void {
            $table->integer('id')->index();
            $table->string('name');
        });
        $reporting->table('events')->insert(['id' => 7, 'name' => 'Existing event']);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_reporting.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::connection('reporting')->create('events', function ($table): void {
            $table->integer('id')->index();
            $table->string('name');
        });
        \Illuminate\Support\Facades\DB::purge('reporting');
        \Illuminate\Support\Facades\DB::connection('reporting')->table('events')->insert(['id' => 1, 'name' => 'Replay event']);
        \Illuminate\Support\Facades\DB::build(config('database.connections.reporting'))->getSchemaBuilder()->create('batches', function ($table): void {
            $table->integer('id')->index();
        });
    }
};
PHP);
        $before = $fixture->fingerprint();
        $namespace = $driver === 'pgsql' ? $fixture->schema : '@database';
        $plan = $fixture->align->plan(['all-supported']);

        expect(array_column($plan['operations'], 'table'))->toContain($namespace.'.report_batches')
            ->not->toContain($namespace.'.events', $namespace.'.batches', $namespace.'.report_events')
            ->and($fixture->fingerprint())->toBe($before);
        $fixture->align->apply($plan['id'], $plan['signature']);
        expect($fixture->align->diff()['differences'])->toBe([])
            ->and($reporting->table('events')->pluck('name')->all())->toBe(['Existing event'])
            ->and($reporting->getSchemaBuilder()->hasTable('batches'))->toBeTrue();
    } finally {
        if ($driver === 'mysql') {
            $fixture->pdo->exec('DROP TABLE IF EXISTS report_events, report_batches, events, batches');
        }
        $fixture->close();
    }
})->with([['pgsql', false], ['pgsql', true], ['mysql', false], ['mysql', true]]);

it('does not inherit selected schema generation settings absent from an auxiliary connection', function (string $driver): void {
    if (getenv($driver === 'pgsql' ? 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS' : 'SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable database app tests are required.');
    }
    $fixture = $driver === 'pgsql' ? new InstalledAppFixture($this->app) : new MySqlInstalledFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $selected = 'database.connections.'.$config->get('database.default');
        $connection = $config->get($selected);
        $config->set($selected, array_replace($connection, [
            'prefix' => 'selected_', 'prefix_indexes' => true,
            'charset' => 'latin1', 'collation' => 'latin1_bin', 'engine' => 'MyISAM',
        ]));
        unset($connection['prefix'], $connection['prefix_indexes'], $connection['charset'], $connection['collation'], $connection['engine']);
        $config->set('database.connections.reporting', $connection);
        $fixture->app->make('db')->connection('reporting')->getSchemaBuilder()->create('events', function (Blueprint $table): void {
            $table->integer('id')->index();
            $table->string('name');
        });
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_reporting.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::connection('reporting')->create('events', function ($table): void {
            $table->integer('id')->index();
            $table->string('name');
        });
    }
};
PHP);
        $before = $fixture->fingerprint();
        $table = ($driver === 'pgsql' ? $fixture->schema : '@database').'.events';
        $comparison = $fixture->align->diff();

        expect($comparison['desired']['tables'])->toHaveKey($table)
            ->and($comparison['desired']['tables'][$table])->toBe($comparison['actual']['tables'][$table])
            ->and($fixture->fingerprint())->toBe($before);
    } finally {
        if ($driver === 'mysql') {
            $fixture->pdo->exec('DROP TABLE IF EXISTS events');
        }
        $fixture->close();
    }
})->with(['pgsql', 'mysql']);

it('rejects named replay connections outside the selected target before producing a plan', function (string $driver, string $configuration): void {
    if (getenv($driver === 'pgsql' ? 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS' : 'SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable database app tests are required.');
    }
    $fixture = $driver === 'pgsql' ? new InstalledAppFixture($this->app) : new MySqlInstalledFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.'.$config->get('database.default'));
        $name = $configuration === 'driver name' ? $driver : 'reporting';
        $connection = match ($configuration) {
            'database', 'driver name' => array_replace($connection, ['database' => $connection['database'].'-other']),
            'url' => array_replace($connection, ['url' => $driver.'://localhost:1/other_database']),
            'driver' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'host' => array_replace($connection, ['host' => 'other.invalid']),
            'port' => array_replace($connection, ['port' => 1]),
            'read', 'write', 'direct' => array_replace($connection, [$configuration => ['database' => $connection['database'].'-other']]),
        };
        $config->set('database.connections.'.$name, $connection);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_reporting.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::connection('.var_export($name, true).')->create("reporting_records", function ($table): void { $table->integer("id"); }); } };');
        $before = $fixture->fingerprint();

        expect(fn () => $fixture->align->plan(['all-supported']))->toThrow(RuntimeException::class, 'unsupported_replay_connection')
            ->and($fixture->fingerprint())->toBe($before);
    } finally {
        $fixture->close();
    }
})->with([
    ['pgsql', 'database'], ['mysql', 'database'],
    ['pgsql', 'driver name'], ['mysql', 'driver name'],
    ['pgsql', 'url'], ['mysql', 'url'],
    ['pgsql', 'driver'], ['mysql', 'driver'],
    ['pgsql', 'host'], ['pgsql', 'port'],
    ['pgsql', 'read'], ['pgsql', 'write'], ['pgsql', 'direct'],
]);

it('replays and applies same target aliases including reconnects', function (string $driver, bool $url): void {
    if (getenv($driver === 'pgsql' ? 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS' : 'SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable database app tests are required.');
    }
    $fixture = $driver === 'pgsql' ? new InstalledAppFixture($this->app) : new MySqlInstalledFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.'.$config->get('database.default'));

        if ($url) {
            $connection['url'] = $driver.'://'.rawurlencode($connection['username']).':'.rawurlencode($connection['password'] ?? '')
                .'@'.$connection['host'].':'.$connection['port'].'/'.$connection['database'];
            unset($connection['host'], $connection['port'], $connection['database'], $connection['username'], $connection['password']);
        }
        $config->set('database.connections.reporting', $connection);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_reporting.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::connection('reporting')->create('reporting_records', function ($table): void {
            $table->integer('id');
        });
        \Illuminate\Support\Facades\DB::purge('reporting');
        \Illuminate\Support\Facades\DB::connection('reporting')->table('reporting_records')->insert(['id' => 1]);
    }
};
PHP);
        $before = $fixture->fingerprint();
        $plan = $fixture->align->plan(['all-supported']);
        expect($fixture->fingerprint())->toBe($before);
        $fixture->align->apply($plan['id'], $plan['signature']);
        expect($fixture->align->diff()['differences'])->toBe([]);
        $table = $driver === 'pgsql' ? $fixture->quotedSchema.'.reporting_records' : 'reporting_records';
        expect((int) $fixture->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn())->toBe(0);
    } finally {
        if ($driver === 'mysql') {
            $fixture->pdo->exec('DROP TABLE IF EXISTS reporting_records');
        }
        $fixture->close();
    }
})->with([['pgsql', false], ['pgsql', true], ['mysql', false], ['mysql', true]]);

it('uses the resolved target driver for dynamic replay connection URLs', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable PostgreSQL app tests are required.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.installed_target');
        $url = 'pgsql://'.rawurlencode($connection['username']).':'.rawurlencode($connection['password'] ?? '')
            .'@'.$connection['host'].':'.$connection['port'].'/'.$connection['database'];
        $config->set('database.connections.reporting', array_replace($connection, [
            'driver' => 'sqlite', 'database' => ':memory:', 'url' => $url,
        ]));
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_reporting.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\DB::build(config('database.connections.reporting'))->getSchemaBuilder()->create('reporting_records', function ($table): void {
            $table->integer('id');
        });
    }
};
PHP);
        $before = $fixture->fingerprint();
        expect($fixture->align->diff()['desired']['tables'])->toHaveKey($fixture->schema.'.reporting_records')
            ->and($fixture->fingerprint())->toBe($before);
    } finally {
        $fixture->close();
    }
});
