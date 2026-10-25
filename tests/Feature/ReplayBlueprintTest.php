<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Fluent;
use Korioinc\SchemaAlign\Replay\Schema\ReplayBlueprint;
use Korioinc\SchemaAlign\Replay\Schema\ReplayMySqlBuilder;
use Korioinc\SchemaAlign\Replay\Schema\ReplayPostgresBuilder;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

it('preserves native blueprint columns or refuses unsupported construction hooks', function (string $driver, string $hook): void {
    $connection = $driver === 'mysql'
        ? new MySqlConnection(new PDO('sqlite::memory:'), 'testing', '', ['version' => '8.4.11'])
        : new PostgresConnection(new PDO('sqlite::memory:'));
    $connection->useDefaultSchemaGrammar();
    $app = $this->app;
    $extend = static function (Blueprint $blueprint, Container $container) use ($app): Blueprint {
        expect($container)->toBe($app);
        $blueprint->bigInteger('tenant_id');
        $blueprint->unique('tenant_id');

        return $blueprint;
    };
    $before = static function (string $abstract, array $parameters, Container $container) use ($extend): void {
        if ($abstract === Blueprint::class) {
            expect($parameters)->toHaveKeys(['connection', 'table', 'callback'])
                ->and($container->bound(Blueprint::class))->toBeFalse();
            $container->extend(Blueprint::class, $extend);
        }
    };

    if (str_starts_with($hook, 'global')) {
        $method = lcfirst(substr($hook, strlen('global')));
        $app->{$method}($method === 'beforeResolving' ? $before : static function (mixed $object, Container $container) use ($extend): void {
            if ($object instanceof Blueprint) {
                $extend($object, $container);
            }
        });
    } else {
        $app->{$hook}(Blueprint::class, $hook === 'beforeResolving' ? $before : $extend);
    }
    $callback = static function (Blueprint $table): void {
        $table->integer('id');
    };
    $normalSql = $connection->pretend(fn () => $connection->getSchemaBuilder()->create('tenants', $callback));
    expect($normalSql[0]['query'])->toContain('tenant_id');

    if (in_array($hook, ['beforeResolving', 'globalBeforeResolving'], true)) {
        $app->forgetExtenders(Blueprint::class);
    }
    $replay = $driver === 'mysql' ? new ReplayMySqlBuilder($connection) : new ReplayPostgresBuilder($connection);

    if (in_array($hook, ['beforeResolving', 'globalBeforeResolving', 'extend'], true)) {
        expect(fn () => $connection->pretend(fn () => $replay->create('tenants', $callback)))
            ->toThrow(RuntimeException::class, 'unsupported_blueprint_construction');

        return;
    }
    $replaySql = $connection->pretend(fn () => $replay->create('tenants', $callback));

    expect(array_column($replaySql, 'query'))->toBe(array_column($normalSql, 'query'))
        ->and($app->bound(Blueprint::class))->toBeFalse();
})->with(['mysql', 'pgsql'])->with([
    'beforeResolving', 'resolving', 'afterResolving', 'extend',
    'globalBeforeResolving', 'globalResolving', 'globalAfterResolving',
]);

it('preserves native resolving callback order and logical names with the application container', function (string $driver): void {
    $connection = $driver === 'mysql'
        ? new MySqlConnection(new PDO('sqlite::memory:'), 'testing', '', ['version' => '8.4.11', 'schema_align_schema_map' => ['logical' => 'scratch']])
        : new PostgresConnection(new PDO('sqlite::memory:'), 'testing', '', ['schema_align_schema_map' => ['logical' => 'scratch']]);
    $connection->useDefaultSchemaGrammar();
    $app = $this->app;
    $steps = [];
    foreach (['globalResolving', 'resolving', 'globalAfterResolving', 'afterResolving'] as $step) {
        $callback = static function (mixed $object, Container $container) use ($app, $step, &$steps): void {
            if ($object instanceof Blueprint) {
                expect($container)->toBe($app);
                $steps[] = $step;

                if ($step === 'afterResolving') {
                    $object->bigInteger('tenant_id')->unique();
                }
            }
        };

        if (str_starts_with($step, 'global')) {
            $app->{lcfirst(substr($step, strlen('global')))}($callback);
        } else {
            $app->{$step}(Blueprint::class, $callback);
        }
    }
    $callback = static function (Blueprint $table): void {
        $table->integer('id');
    };
    $normalSql = $connection->pretend(fn () => $connection->getSchemaBuilder()->create('logical.tenants', $callback));
    $nativeSteps = $steps;
    $steps = [];
    $replay = $driver === 'mysql' ? new ReplayMySqlBuilder($connection) : new ReplayPostgresBuilder($connection);
    $replaySql = $connection->pretend(fn () => $replay->create('scratch.tenants', $callback));

    expect($steps)->toBe($nativeSteps)
        ->and(implode('\n', array_column($replaySql, 'query')))->toContain('tenant_id', 'logical_tenants_tenant_id_unique')
        ->and(str_replace('scratch', 'logical', array_column($replaySql, 'query')))->toBe(array_column($normalSql, 'query'))
        ->and($app->bound(Blueprint::class))->toBeFalse()
        ->and($app->bound(ReplayBlueprint::class))->toBeFalse();
})->with(['mysql', 'pgsql']);

it('refuses aliased extenders and contextual blueprint replacements without changing the container', function (string $driver, string $customization): void {
    $connection = $driver === 'mysql'
        ? new MySqlConnection(new PDO('sqlite::memory:'), 'testing', '', ['version' => '8.4.11'])
        : new PostgresConnection(new PDO('sqlite::memory:'));
    $connection->useDefaultSchemaGrammar();
    $app = $this->app;
    $app->alias(Blueprint::class, 'tenant.blueprint');

    if ($customization === 'extender') {
        $app->extend('tenant.blueprint', static function (Blueprint $blueprint): Blueprint {
            $blueprint->bigInteger('tenant_id');

            return $blueprint;
        });
    } else {
        $app->when(self::class)->needs('tenant.blueprint')->give(ReplayBlueprint::class);
    }
    $bindings = $app->getBindings();
    $contextual = $app->contextual;
    $replay = $driver === 'mysql' ? new ReplayMySqlBuilder($connection) : new ReplayPostgresBuilder($connection);

    expect(fn () => $connection->pretend(fn () => $replay->create('tenants', static function (Blueprint $table): void {
        $table->integer('id');
    })))->toThrow(RuntimeException::class, 'unsupported_blueprint_construction')
        ->and($app->getBindings())->toBe($bindings)
        ->and($app->contextual)->toBe($contextual)
        ->and($app->getAlias('tenant.blueprint'))->toBe(Blueprint::class);
})->with(['mysql', 'pgsql'])->with(['extender', 'contextual']);

it('refuses blueprint extenders registered inside table callbacks before executing schema changes', function (string $driver): void {
    $connection = $driver === 'mysql'
        ? new MySqlConnection(new PDO('sqlite::memory:'), 'testing', '', ['version' => '8.4.11'])
        : new PostgresConnection(new PDO('sqlite::memory:'));
    $connection->useDefaultSchemaGrammar();
    $app = $this->app;
    $callback = static function (Blueprint $table) use ($app): void {
        $table->integer('id');
        $app->extend(Blueprint::class, static function (Blueprint $blueprint): Blueprint {
            $blueprint->bigInteger('tenant_id');

            return $blueprint;
        });
    };
    $normalSql = $connection->pretend(fn () => $connection->getSchemaBuilder()->table('tenants', $callback));
    expect(implode('\n', array_column($normalSql, 'query')))->toContain('tenant_id');
    $app->forgetExtenders(Blueprint::class);
    $replay = $driver === 'mysql' ? new ReplayMySqlBuilder($connection) : new ReplayPostgresBuilder($connection);

    expect(fn () => $connection->pretend(fn () => $replay->table('tenants', $callback)))
        ->toThrow(RuntimeException::class, 'unsupported_blueprint_construction');
    expect($connection->getQueryLog())->toBe([]);
    $normalSql = $connection->pretend(fn () => $connection->getSchemaBuilder()->table('tenants', static function (Blueprint $table): void {
        $table->integer('id');
    }));
    expect(implode('\n', array_column($normalSql, 'query')))->toContain('tenant_id');
})->with(['mysql', 'pgsql']);

it('refuses container blueprint bindings before replay can omit custom columns', function (string $driver): void {
    $connection = $driver === 'mysql'
        ? new MySqlConnection(new PDO('sqlite::memory:'), 'testing', '', ['version' => '8.4.11'])
        : new PostgresConnection(new PDO('sqlite::memory:'));
    $this->app->bind(Blueprint::class, function ($app, array $parameters): Blueprint {
        return new class(...array_values($parameters)) extends Blueprint
        {
            public function create(): Fluent
            {
                $this->bigInteger('tenant_id');

                return parent::create();
            }
        };
    });
    $callback = static function (Blueprint $table): void {
        $table->integer('id');
    };
    $normalSql = $connection->pretend(fn () => $connection->getSchemaBuilder()->create('tenants', $callback));
    expect($normalSql[0]['query'])->toContain('tenant_id');
    $replay = $driver === 'mysql' ? new ReplayMySqlBuilder($connection) : new ReplayPostgresBuilder($connection);
    $called = false;
    $callback = function (Blueprint $table) use (&$called): void {
        $called = true;
        $table->integer('id');
    };

    expect(fn () => $connection->pretend(fn () => $replay->create('tenants', $callback)))
        ->toThrow(RuntimeException::class, 'unsupported_blueprint_binding');
    expect($called)->toBeFalse();
})->with(['mysql', 'pgsql']);

it('refuses a captured application blueprint binding before publishing a plan', function (string $driver): void {
    if (getenv($driver === 'mysql' ? 'SCHEMA_ALIGN_MYSQL_APP_TESTS' : 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable installed application tests are required.');
    }
    $fixture = $driver === 'mysql' ? new MySqlInstalledFixture($this->app) : new InstalledAppFixture($this->app);

    try {
        $namespace = 'BlueprintFixture'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists($fixture->directory.'/app/Providers');
        file_put_contents($fixture->directory.'/app/Providers/TenantBlueprintProvider.php', '<?php namespace '.$namespace.';'.<<<'PHP'

class TenantBlueprintProvider extends \Illuminate\Support\ServiceProvider {
    public function register(): void {
        $this->app->bind(\Illuminate\Database\Schema\Blueprint::class, function ($app, array $parameters) {
            return new class(...array_values($parameters)) extends \Illuminate\Database\Schema\Blueprint {
                public function create() {
                    $this->bigInteger('tenant_id');
                    return parent::create();
                }
            };
        });
    }
}
PHP);
        $providers = $fixture->directory.'/bootstrap/providers.php';
        file_put_contents($providers, str_replace('return [', "require_once dirname(__DIR__).'/app/Providers/TenantBlueprintProvider.php';\nreturn [\\".$namespace.'\\TenantBlueprintProvider::class, ', file_get_contents($providers)));
        $before = $fixture->fingerprint();

        expect(fn () => $fixture->align->plan(['all-supported']))->toThrow(RuntimeException::class, 'unsupported_blueprint_binding')
            ->and($fixture->fingerprint())->toBe($before);
    } finally {
        $fixture->close();
    }
})->with(['mysql', 'pgsql']);

it('preserves installed provider columns or refuses unsupported construction before planning', function (string $driver, string $hook): void {
    if (getenv($driver === 'mysql' ? 'SCHEMA_ALIGN_MYSQL_APP_TESTS' : 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable installed application tests are required.');
    }
    $fixture = $driver === 'mysql' ? new MySqlInstalledFixture($this->app) : new InstalledAppFixture($this->app);

    try {
        $namespace = 'BlueprintHookFixture'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists($fixture->directory.'/app/Providers');
        $provider = $fixture->directory.'/app/Providers/TenantBlueprintProvider.php';
        file_put_contents($provider, '<?php namespace '.$namespace.';'.str_replace('__HOOK__', $hook, <<<'PHP'

class TenantBlueprintProvider extends \Illuminate\Support\ServiceProvider {
    public function register(): void {
        $this->app->__HOOK__(\Illuminate\Database\Schema\Blueprint::class, function ($blueprint) {
            $blueprint->bigInteger('tenant_id')->nullable();
            return $blueprint;
        });
    }
}
PHP));
        $providers = $fixture->directory.'/bootstrap/providers.php';
        file_put_contents($providers, str_replace('return [', "require_once dirname(__DIR__).'/app/Providers/TenantBlueprintProvider.php';\nreturn [\\".$namespace.'\\TenantBlueprintProvider::class, ', file_get_contents($providers)));
        require $provider;
        $fixture->app->register($namespace.'\\TenantBlueprintProvider');
        $connection = $fixture->app->make('db')->connection();
        $normalSql = $connection->pretend(fn () => $connection->getSchemaBuilder()->create('tenant_probe', static function (Blueprint $table): void {
            $table->bigInteger('id');
        }));
        expect($normalSql[0]['query'])->toContain('tenant_id');
        $before = $fixture->fingerprint();

        if ($hook === 'extend') {
            expect(fn () => $fixture->align->plan(['all-supported']))->toThrow(RuntimeException::class, 'unsupported_blueprint_construction')
                ->and($fixture->fingerprint())->toBe($before);

            return;
        }
        $plan = $fixture->align->plan(['all-supported']);
        expect($fixture->fingerprint())->toBe($before);
        $fixture->align->apply($plan['id'], $plan['signature']);
        $table = $driver === 'mysql' ? '`'.MySqlInstalledFixture::TABLE.'`' : $fixture->quotedSchema.'.sample_records';
        $fixture->pdo->exec('UPDATE '.$table.' SET tenant_id = 73 WHERE id = 1');
        expect((int) $fixture->pdo->query('SELECT tenant_id FROM '.$table.' WHERE id = 1')->fetchColumn())->toBe(73)
            ->and($driver === 'mysql' ? $fixture->itemName() : $fixture->recordName())
            ->toBe($driver === 'mysql' ? 'Sample item A' : 'Sample record A');
    } finally {
        $fixture->close();
    }
})->with(['mysql', 'pgsql'])->with(['afterResolving', 'extend']);

it('preserves ordinary replay schema creation without a blueprint binding', function (string $driver): void {
    $connection = $driver === 'mysql'
        ? new MySqlConnection(new PDO('sqlite::memory:'), 'testing', '', ['version' => '8.4.11'])
        : new PostgresConnection(new PDO('sqlite::memory:'));
    $connection->useDefaultSchemaGrammar();
    $replay = $driver === 'mysql' ? new ReplayMySqlBuilder($connection) : new ReplayPostgresBuilder($connection);
    $sql = $connection->pretend(fn () => $replay->create('tenants', function (Blueprint $table): void {
        $table->integer('id');
    }));

    expect($sql[0]['query'])->toContain('tenants', 'id');
})->with(['mysql', 'pgsql']);
