<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\SchemaAlign;

it('resolves the singleton', function () {
    expect(app(SchemaAlign::class))->toBeInstanceOf(SchemaAlign::class);
});

it('returns the same instance from the container', function () {
    expect(app(SchemaAlign::class))->toBe(app(SchemaAlign::class));
});

it('merges the package config', function () {
    expect(config('laravel-schema-align.placeholder'))->toBe('default');
});

it('registers the artisan command', function () {
    $this->artisan('laravel-schema-align:placeholder')
        ->expectsOutputToContain('SchemaAlign placeholder command executed.')
        ->assertSuccessful();
});
