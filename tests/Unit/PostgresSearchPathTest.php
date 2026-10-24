<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Replay\PostgresSearchPath;

it('maps PostgreSQL search paths without changing their order or scope', function (array $config, array $expected): void {
    $mapping = ['fixture_scope' => 'align-1023-aaaaa', 'reporting' => 'align-1023-bbbbb'];

    expect((new PostgresSearchPath)->map($config, $mapping))->toBe($expected);
})->with([
    'one schema' => [['search_path' => 'reporting'], ['align-1023-bbbbb']],
    'ordered string' => [['search_path' => 'reporting, fixture_scope'], ['align-1023-bbbbb', 'align-1023-aaaaa']],
    'quoted string' => [['search_path' => '"reporting", "fixture_scope"'], ['align-1023-bbbbb', 'align-1023-aaaaa']],
    'ordered array' => [['search_path' => ['reporting', 'fixture_scope']], ['align-1023-bbbbb', 'align-1023-aaaaa']],
    'quoted array' => [['search_path' => ['"reporting"', '"fixture_scope"']], ['align-1023-bbbbb', 'align-1023-aaaaa']],
    'legacy schema' => [['schema' => 'reporting'], ['align-1023-bbbbb']],
    'search path takes precedence' => [['search_path' => 'reporting', 'schema' => 'fixture_scope'], ['align-1023-bbbbb']],
    'null search path uses schema' => [['search_path' => null, 'schema' => 'reporting'], ['align-1023-bbbbb']],
]);

it('refuses PostgreSQL search paths that cannot be mapped exactly', function (array $config): void {
    $mapping = ['fixture_scope' => 'align-1023-aaaaa', 'reporting' => 'align-1023-bbbbb', '$user' => 'align-1023-ccccc'];

    expect(fn () => (new PostgresSearchPath)->map($config, $mapping))
        ->toThrow(RuntimeException::class, 'unsupported_replay_search_path');
})->with([
    'server default' => [[]],
    'null path' => [['search_path' => null]],
    'empty string' => [['search_path' => '']],
    'empty list' => [['search_path' => []]],
    'empty schema name' => [['search_path' => ['']]],
    'invalid type' => [['search_path' => false]],
    'invalid entry' => [['search_path' => ['reporting', null]]],
    'nested entry' => [['search_path' => [['reporting']]]],
    'unmapped schema' => [['search_path' => ['reporting', 'outside_scope']]],
    'dynamic user schema' => [['search_path' => '"$user", reporting']],
]);
