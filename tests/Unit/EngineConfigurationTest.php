<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\EngineConfiguration;

it('resolves the physical migration ledger from the connection prefix', function (string $driver): void {
    expect(EngineConfiguration::physicalMigrationTable(['driver' => $driver, 'prefix' => 'p_'], 'migrations'))->toBe('p_migrations')
        ->and(EngineConfiguration::physicalMigrationTable(['driver' => $driver, 'prefix' => 'tenant_'], 'schema_history'))->toBe('tenant_schema_history')
        ->and(EngineConfiguration::physicalMigrationTable(['driver' => $driver], 'migrations'))->toBe('migrations');
})->with(['pgsql', 'mysql']);

it('rejects invalid physical ledger names before connecting', function (array $connection, string $logical): void {
    expect(fn () => EngineConfiguration::physicalMigrationTable($connection, $logical))->toThrow(InvalidArgumentException::class);
})->with([
    [['driver' => 'pgsql', 'prefix' => ['p_']], 'migrations'],
    [['driver' => 'pgsql', 'prefix' => "p\0"], 'migrations'],
    [['driver' => 'pgsql', 'prefix' => str_repeat('a', 54)], 'migrations'],
    [['driver' => 'mysql', 'prefix' => str_repeat('a', 55)], 'migrations'],
    [['driver' => 'pgsql', 'prefix' => str_repeat('é', 27)], 'migrations'],
    [['driver' => 'mysql', 'prefix' => '__schema_'], 'align_run'],
    [['driver' => 'mysql', 'prefix' => '__SCHEMA_'], 'align_run'],
]);

it('allows engine-specific physical identifier length limits', function (): void {
    expect(EngineConfiguration::physicalMigrationTable(['driver' => 'pgsql', 'prefix' => str_repeat('a', 53)], 'migrations'))->toHaveLength(63)
        ->and(EngineConfiguration::physicalMigrationTable(['driver' => 'mysql', 'prefix' => str_repeat('a', 54)], 'migrations'))->toHaveLength(64)
        ->and(EngineConfiguration::physicalMigrationTable(['driver' => 'mysql', 'prefix' => str_repeat('é', 54)], 'migrations'))->toBe(str_repeat('é', 54).'migrations');
});

it('selects per-engine configuration without sharing PostgreSQL namespace settings with MySQL', function (): void {
    $defaults = require dirname(__DIR__, 2).'/config/schema-align.php';
    $pgsql = EngineConfiguration::resolve($defaults, 'pgsql');
    $mysql = EngineConfiguration::resolve($defaults, 'mysql');
    expect($pgsql['schemas'])->toBe(['public'])
        ->and($mysql['schemas'])->toBe(['@database'])
        ->and($mysql['replay']['driver'])->toBe('mysql');
    $overrides = $defaults;
    $overrides['engines']['pgsql']['schemas'] = ['tenant'];
    $overrides['engines']['mysql']['migration_table'] = 'schema_history';
    expect(EngineConfiguration::resolve($overrides, 'pgsql')['schemas'])->toBe(['tenant'])
        ->and(EngineConfiguration::resolve($overrides, 'mysql')['migration_table'])->toBe('schema_history');
});

it('retains previously published flat PostgreSQL settings without silently choosing a different schema', function (): void {
    $legacy = ['connection' => null, 'schemas' => ['legacy_tenant'], 'migration_table' => 'old_migrations', 'lock_timeout_ms' => 2000, 'statement_timeout_ms' => 10000,
        'replay' => ['postgres_image' => null, 'postgres_args' => [], 'extensions' => [], 'schemas' => ['public']]];
    $resolved = EngineConfiguration::resolve($legacy, 'pgsql');
    expect($resolved['schemas'])->toBe(['legacy_tenant'])
        ->and($resolved['migration_table'])->toBe('old_migrations');
});

it('refuses explicit engine mismatch and conflicting legacy settings', function (): void {
    expect(fn () => EngineConfiguration::resolve(['driver' => 'pgsql'], 'mysql'))->toThrow(InvalidArgumentException::class, 'driver')
        ->and(fn () => EngineConfiguration::resolve(['schemas' => ['one'], 'engines' => ['pgsql' => ['schemas' => ['two']]]], 'pgsql'))->toThrow(InvalidArgumentException::class, 'Conflicting')
        ->and(fn () => EngineConfiguration::resolve(['schemas' => ['public']], 'mysql'))->toThrow(InvalidArgumentException::class, 'Legacy');
});

it('rejects an empty explicit PostgreSQL scope before any legacy fallback', function (array $legacy): void {
    $config = array_replace($legacy, ['engines' => ['pgsql' => ['schemas' => []]]]);

    expect(fn () => EngineConfiguration::resolve($config, 'pgsql'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'without legacy settings' => [[]],
    'flat legacy scope' => [['schemas' => ['legacy']]],
    'replay legacy scope' => [['replay' => ['schemas' => ['legacy']]]],
]);

it('rejects conflicting replay scopes instead of silently changing the target', function (array $config): void {
    expect(fn () => EngineConfiguration::resolve($config, 'pgsql'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'engine and replay' => [['engines' => ['pgsql' => ['schemas' => ['tenant']]], 'replay' => ['schemas' => ['legacy']]]],
    'flat and replay' => [['schemas' => ['tenant'], 'replay' => ['schemas' => ['legacy']]]],
    'explicit flat public and custom replay' => [['schemas' => ['public'], 'replay' => ['schemas' => ['legacy']]]],
    'empty replay with a valid engine' => [['engines' => ['pgsql' => ['schemas' => ['tenant']]], 'replay' => ['schemas' => []]]],
    'empty flat scope with merged defaults' => [['schemas' => []]],
]);

it('does not widen the target scope when replacing multiple schemas', function (): void {
    $resolved = EngineConfiguration::resolve(['engines' => ['pgsql' => ['schemas' => ['tenant_b', 'tenant_a']]]], 'pgsql');

    expect($resolved['schemas'])->not->toContain('public');
});

it('does not substitute the merged engine default for a published legacy scope', function (string $location): void {
    $config = require dirname(__DIR__, 2).'/config/schema-align.php';

    if ($location === 'flat') {
        $config['schemas'] = ['legacy'];
        $config['replay']['schemas'] = ['public'];
    } else {
        $config['replay']['schemas'] = ['legacy'];
    }

    expect(EngineConfiguration::resolve($config, 'pgsql')['schemas'])->not->toContain('public');
})->with(['flat', 'replay']);
