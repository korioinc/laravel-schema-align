<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\PathPackageFixture;

beforeEach(function (): void {
    if (getenv('SCHEMA_ALIGN_PATH_PACKAGE_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_PATH_PACKAGE_TESTS=1 with the disposable PostgreSQL target.');
    }
});

afterEach(function (): void {
    if (isset($this->pathFixture)) {
        $this->pathFixture->close();
    }
});

it('replays a Composer-installed external path package against the consumer database and detects edited migration bytes', function (bool $symlink): void {
    $fixture = $this->pathFixture = new PathPackageFixture($this->app, symlink: $symlink);
    $consumer = $fixture->consumer;
    $installed = json_decode(file_get_contents($consumer->directory.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $package = collect($installed['packages'])->firstWhere('name', 'korioinc/laravel-schema-align');
    expect($package['dist']['url'])->toBe($fixture->packageDirectory)
        ->and(realpath($consumer->directory.'/vendor/composer/'.$package['install-path']))->toBe(realpath($fixture->installedPath))
        ->and(is_link($fixture->installedPath))->toBe($symlink);

    if (! $symlink) {
        expect(realpath($fixture->installedPath))->not->toBe(realpath($package['dist']['url']));
    }
    $before = $consumer->fingerprint();
    $ledger = $consumer->pdo->query('SELECT migration, batch FROM '.$consumer->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $sentinel = getenv('SCHEMA_ALIGN_HOST_SENTINEL');
    putenv('SCHEMA_ALIGN_HOST_SENTINEL=host-only-path-package');

    try {
        $initial = $consumer->align->diff();

        if (! $symlink) {
            $fixture->addPackageColumn();
            $sourceEdited = $consumer->align->diff();
            expect($sourceEdited['source']['revision'])->toBe($initial['source']['revision'])
                ->and($sourceEdited['desired']['tables'][$consumer->schema.'.path_package_notes']['columns'])->not->toHaveKey('label');
        }
        $fixture->addPackageColumn(installed: true);
        $edited = $consumer->align->diff();
    } finally {
        putenv($sentinel === false ? 'SCHEMA_ALIGN_HOST_SENTINEL' : 'SCHEMA_ALIGN_HOST_SENTINEL='.$sentinel);
    }
    expect($edited['source']['revision'])->not->toBe($initial['source']['revision'])
        ->and($edited['desired']['tables'][$consumer->schema.'.path_package_notes']['columns'])->toHaveKey('label')
        ->and($consumer->fingerprint())->toBe($before)
        ->and($consumer->recordName())->toBe('Sample record A');
    $plan = $consumer->align->plan(['all-supported']);
    expect($plan['source']['revision'])->toBe($edited['source']['revision']);
    $consumer->align->apply($plan['id'], $plan['signature']);
    $consumer->pdo->exec('INSERT INTO '.$consumer->quotedSchema.".path_package_notes (id, label) VALUES (42, 'consumer package migration')");
    expect($consumer->pdo->query('SELECT label FROM '.$consumer->quotedSchema.'.path_package_notes WHERE id = 42')->fetchColumn())->toBe('consumer package migration')
        ->and($consumer->recordName())->toBe('Sample record A')
        ->and($consumer->pdo->query('SELECT migration, batch FROM '.$consumer->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
})->with(['symlinked installation' => true, 'copied installation' => false]);

it('replays installed path packages after Composer omits locked development dependencies', function (): void {
    $fixture = $this->pathFixture = new PathPackageFixture($this->app);
    $fixture->removeDevelopmentDependencies();
    $consumer = $fixture->consumer;
    $lock = json_decode(file_get_contents($consumer->directory.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $installed = json_decode(file_get_contents($consumer->directory.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $developmentPackage = collect($lock['packages-dev'])->firstWhere('name', PathPackageFixture::DEVELOPMENT_PACKAGE);
    expect($developmentPackage['dist']['type'])->toBe('path')
        ->and(collect($installed['packages'])->pluck('name'))->not->toContain(PathPackageFixture::DEVELOPMENT_PACKAGE)
        ->and(is_dir($consumer->directory.'/vendor/'.PathPackageFixture::DEVELOPMENT_PACKAGE))->toBeFalse();
    $before = $consumer->fingerprint();

    $diff = $consumer->align->diff();
    $plan = $consumer->align->plan(['all-supported']);

    expect($diff['desired']['tables'])->toHaveKey($consumer->schema.'.path_package_notes')
        ->and($plan['source']['revision'])->toBe($diff['source']['revision'])
        ->and($consumer->fingerprint())->toBe($before)
        ->and($consumer->recordName())->toBe('Sample record A');
});

it('replays a path package installed from a Composer environment-variable URL', function (): void {
    $variable = 'SCHEMA_ALIGN_PATH_PACKAGE_'.strtoupper(bin2hex(random_bytes(8)));
    $previous = getenv($variable);

    try {
        $fixture = $this->pathFixture = new PathPackageFixture($this->app, repositoryEnvironmentVariable: $variable);
        putenv($variable.'='.$fixture->packageDirectory);
        $consumer = $fixture->consumer;
        $composer = json_decode(file_get_contents($consumer->directory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $installed = json_decode(file_get_contents($consumer->directory.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $package = collect($installed['packages'])->firstWhere('name', 'korioinc/laravel-schema-align');
        expect($composer['repositories'][0]['url'])->toBe('$'.$variable)
            ->and($package['dist']['url'])->toBe($fixture->packageDirectory);
        $before = $consumer->fingerprint();

        $diff = $consumer->align->diff();
        $plan = $consumer->align->plan(['all-supported']);

        expect($diff['desired']['tables'])->toHaveKey($consumer->schema.'.path_package_notes')
            ->and($plan['source']['revision'])->toBe($diff['source']['revision'])
            ->and($consumer->fingerprint())->toBe($before)
            ->and($consumer->recordName())->toBe('Sample record A');
    } finally {
        putenv($previous === false ? $variable : $variable.'='.$previous);
    }
});
