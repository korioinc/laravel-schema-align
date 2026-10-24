<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Replay\ReleaseReplayer;

it('refuses configuration that cannot retain its PHP values during replay transport', function (mixed $value): void {
    $input = array_fill_keys(['connection', 'binding', 'identity', 'schemas', 'expected_migrations', 'migration_paths'], []);
    $input['application_config'] = ['custom' => $value];

    expect(fn () => (new ReleaseReplayer)->capture($input))
        ->toThrow(RuntimeException::class, 'JSON-preservable');
})->with([
    'object' => fn (): stdClass => new stdClass,
    'closure' => fn (): Closure => static fn (): string => 'runtime_note',
    'nonfinite number' => INF,
]);
