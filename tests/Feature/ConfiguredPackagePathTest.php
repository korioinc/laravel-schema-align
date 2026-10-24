<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\PathPackageFixture;

it('relocates a configured package migration path before its enclosing app path', function (): void {
    if (getenv('SCHEMA_ALIGN_PATH_PACKAGE_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_PATH_PACKAGE_TESTS=1 with the disposable PostgreSQL target.');
    }
    $fixture = new PathPackageFixture($this->app, insideApp: true);

    try {
        $consumer = $fixture->consumer;
        $before = $consumer->fingerprint();
        $comparison = $consumer->align->diff();

        expect($comparison['desired']['tables'])->toHaveKey($consumer->schema.'.path_package_notes')
            ->and($comparison['source']['migration_names'])->toContain('2026_01_04_000000_create_path_package_notes')
            ->and($consumer->fingerprint())->toBe($before)
            ->and($consumer->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
});
