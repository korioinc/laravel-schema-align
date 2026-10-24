<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;

function platformComparison(array $desired, array $actual): array
{
    return (new SchemaComparator)->compare(new Snapshot([], $desired, []), new Snapshot([], $actual, []));
}

it('accepts minimal platforms and ignores only server patch versions', function (): void {
    expect(platformComparison(['server_major' => 18], ['server_major' => 18])['errors'])->toBe([])
        ->and(platformComparison(['server_major' => 18, 'server_version' => '18.1', 'server_version_num' => 180001], ['server_major' => 18, 'server_version' => '18.2', 'server_version_num' => 180002])['errors'])->toBe([]);
});

it('reports the exact platform field and expected and actual values', function (): void {
    $result = platformComparison(
        ['server_major' => 18, 'database' => ['actual_collation_version' => '2.36']],
        ['server_major' => 18, 'database' => ['actual_collation_version' => '2.41']],
    );
    expect(implode("\n", $result['errors']))->toContain('platform.database.actual_collation_version', 'expected="2.36"', 'actual="2.41"')
        ->and($result['differences'])->toBe([]);
});

it('compares every relevant named collation including ones present on only one side', function (bool $reverse): void {
    $base = ['server_major' => 18, 'collations' => ['pg_catalog.default' => ['collprovider' => 'd']]];
    $secondary = $base;
    $secondary['collations']['pg_catalog.en_US.utf8'] = ['collprovider' => 'c', 'collversion' => '2.36', 'actual_version' => '2.36'];
    $result = $reverse ? platformComparison($secondary, $base) : platformComparison($base, $secondary);
    expect(implode("\n", $result['errors']))->toContain('platform.collations["pg_catalog.en_US.utf8"]', '<missing>');
})->with([false, true]);

it('keeps provider determinism locale and version differences blocking', function (string $field, mixed $before, mixed $after): void {
    $desired = ['server_major' => 18, 'collations' => ['public.secondary' => [$field => $before]]];
    $actual = ['server_major' => 18, 'collations' => ['public.secondary' => [$field => $after]]];
    expect(implode("\n", platformComparison($desired, $actual)['errors']))->toContain('platform.collations["public.secondary"].'.$field);
})->with([
    ['collprovider', 'c', 'i'], ['collisdeterministic', true, false],
    ['collcollate', 'en_US.utf8', 'en_GB.utf8'], ['collctype', 'en_US.utf8', 'en_US.utf8@euro'],
    ['locale', 'en_US.utf8', 'en_US.UTF-8'], ['collversion', '2.36', '2.41'], ['actual_version', '2.36', '2.41'],
]);

it('rejects identically stale database and named collation versions', function (): void {
    $platform = ['server_major' => 18,
        'database' => ['collation_version' => '2.36', 'actual_collation_version' => '2.41'],
        'collations' => ['public.secondary' => ['collversion' => '2.36', 'actual_version' => '2.41']],
    ];
    $errors = implode("\n", platformComparison($platform, $platform)['errors']);
    expect($errors)->toContain('desired platform.database.collation_version', 'actual platform.database.collation_version',
        'desired platform.collations["public.secondary"].collversion', 'actual platform.collations["public.secondary"].collversion', '2.36', '2.41');
});

it('allows unversioned collations and canonical locale values collected by the inspector', function (): void {
    $platform = ['server_major' => 18, 'database' => ['collate' => 'en_US.utf8', 'ctype' => 'en_US.utf8', 'collation_version' => null, 'actual_collation_version' => null],
        'collations' => ['pg_catalog.C' => ['collversion' => null, 'actual_version' => null]],
    ];
    expect(platformComparison($platform, $platform)['errors'])->toBe([]);
});

it('fails closed for unknown fields missing metadata and null versus absent values', function (): void {
    foreach ([[[], []], [['server_major' => 18], []], [['future' => null], []], [['future' => ['mode' => 'a']], ['future' => ['mode' => 'b']]]] as [$desired, $actual]) {
        expect(platformComparison($desired, $actual)['errors'])->not->toBeEmpty();
    }
});

it('bounds platform diagnostics and reports truncation', function (): void {
    $desired = ['server_major' => 18, 'settings' => array_fill_keys(range('a', 'z'), 'expected')];
    $actual = ['server_major' => 18, 'settings' => array_fill_keys(range('a', 'z'), 'actual')];
    $errors = platformComparison($desired, $actual)['errors'];
    expect($errors)->toHaveCount(13)
        ->and(implode("\n", $errors))->toContain('14 additional platform diagnostics omitted');
});

it('uses database version evidence for the default collation instead of flagging its null catalog version', function (): void {
    $platform = ['server_major' => 18,
        'database' => ['collation_version' => '2.36', 'actual_collation_version' => '2.36'],
        'collations' => ['pg_catalog.default' => ['nspname' => 'pg_catalog', 'collname' => 'default', 'collprovider' => 'd', 'collversion' => null, 'actual_version' => '2.36']],
    ];
    expect(platformComparison($platform, $platform)['errors'])->toBe([]);
    $platform['database']['actual_collation_version'] = '2.41';
    expect(implode(' ', platformComparison($platform, $platform)['errors']))->toContain('database.collation_version is stale');
});

it('rejects unavailable actual versions when a recorded version exists', function (): void {
    $platform = ['server_major' => 18, 'database' => ['collation_version' => '2.36', 'actual_collation_version' => null]];
    expect(implode("\n", platformComparison($platform, $platform)['errors']))->toContain('collation_version is stale', 'actual_collation_version=null');
});
