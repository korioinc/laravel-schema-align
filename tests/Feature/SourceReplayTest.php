<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Korioinc\SchemaAlign\Tests\Support\PathPackageFixture;
use Symfony\Component\Process\Process;

it('uses installed development bootstrap and application resources without changing host runtime files', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        mkdir($fixture->directory.'/dev');
        mkdir($fixture->directory.'/schema-input');
        file_put_contents($fixture->directory.'/schema-input/note-column.txt', 'installed_note');
        file_put_contents($fixture->directory.'/dev/bootstrap.php', <<<'PHP'
<?php
function installedSchemaNoteColumn(): string {
    return file_get_contents(dirname(__DIR__).'/schema-input/note-column.txt');
}
PHP);
        $composer = json_decode(file_get_contents($fixture->directory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['autoload-dev']['files'][] = 'dev/bootstrap.php';
        file_put_contents($fixture->directory.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        (new Process(['composer', 'dump-autoload', '--no-scripts', '--no-plugins', '--no-interaction'], $fixture->directory, ['COMPOSER_DISABLE_NETWORK' => '1']))->mustRun();
        $log = $fixture->app->storagePath('logs/replay.log');
        file_put_contents($log, 'Original application log');
        $configPath = $fixture->app->getCachedConfigPath();
        $configBytes = '<?php return '.var_export($fixture->app->make('config')->all(), true).';';
        file_put_contents($configPath, $configBytes);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_10_000000_installed_note.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        file_put_contents(storage_path('logs/replay.log'), 'Worker log');
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string(installedSchemaNoteColumn())->nullable();
        });
    }
};
PHP);
        $before = $fixture->fingerprint();
        $plan = $fixture->align->plan(['all-safe']);

        expect($fixture->fingerprint())->toBe($before)
            ->and(file_get_contents($log))->toBe('Original application log')
            ->and(file_get_contents($configPath))->toBe($configBytes);
        $fixture->align->apply($plan['id'], $plan['signature']);
        $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_records SET installed_note = 'Installed source was used' WHERE id = 1");

        expect($fixture->pdo->query('SELECT installed_note FROM '.$fixture->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('Installed source was used')
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
});

it('refuses a plan when installed migration inputs change during replay', function (string $change): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $operation = match ($change) {
            'edit' => 'file_put_contents(__FILE__, "\\n// Changed migration", FILE_APPEND);',
            'add' => 'file_put_contents(__DIR__."/2026_01_12_000000_late_migration.php", "<?php return new class extends \\\\Illuminate\\\\Database\\\\Migrations\\\\Migration { public function up(): void {} };");',
            'delete' => 'unlink(__FILE__);',
            'lock' => 'file_put_contents(base_path("composer.lock"), "\\n", FILE_APPEND);',
        };
        file_put_contents($fixture->directory.'/database/migrations/2026_01_11_000000_source_change.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { '.$operation.' } };');
        $before = $fixture->fingerprint();

        expect(fn () => $fixture->align->plan(['all-safe']))->toThrow(RuntimeException::class)
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
})->with(['edit', 'add', 'delete', 'lock']);

it('refuses ambiguous duplicate migration names without changing the original database', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $name = '2026_01_01_000000_create_sample_records.php';
        copy($fixture->directory.'/database/migrations/'.$name, $fixture->directory.'/app/provider-migrations/'.$name);
        $before = $fixture->fingerprint();

        expect(fn () => $fixture->align->plan(['all-safe']))->toThrow(RuntimeException::class)
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
});

it('replays migration translations from the Laravel language path', function (string $languagePath): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $directory = $fixture->directory.'/'.$languagePath;
        (new Filesystem)->ensureDirectoryExists($directory.'/en');
        file_put_contents($directory.'/en/schema.php', <<<'PHP'
<?php
return ['statuses' => ['pending', 'active'], 'note_column' => 'translated_note'];
PHP);

        if ($languagePath === 'translations') {
            file_put_contents($fixture->directory.'/bootstrap/app.php', <<<'PHP'
<?php
$app = \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))->create();
$app->useLangPath($app->basePath('translations'));
return $app;
PHP);
        }
        // The consumer has already booted; mirror the path its next bootstrap will select.
        $fixture->app->useLangPath($fixture->app->basePath($languagePath));
        $fixture->app->make('config')->set('app.locale', 'en');
        expect(trans('schema.statuses'))->toBe(['pending', 'active']);
        file_put_contents($fixture->directory.'/database/migrations/2026_01_10_000000_translated_columns.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->enum('status', trans('schema.statuses'))->nullable();
            $table->string(trans('schema.note_column'))->nullable();
        });
    }
};
PHP);
        $before = $fixture->fingerprint();
        $comparison = $fixture->align->diff();
        $plan = $fixture->align->plan(['all-safe']);

        expect($comparison['desired']['tables'][$fixture->schema.'.sample_records']['columns'])->toHaveKeys(['status', 'translated_note'])
            ->and(array_column($plan['operations'], 'name'))->toContain('translated_note')
            ->and($fixture->fingerprint())->toBe($before);
    } finally {
        $fixture->close();
    }
})->with(['root language directory' => 'lang', 'custom language directory' => 'translations', 'legacy language directory' => 'resources/lang']);

it('replays Laravel registered migrations outside conventional source directories', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $directory = $fixture->directory.'/custom-migrations';
        mkdir($directory);
        file_put_contents($directory.'/2026_01_10_000000_custom_note.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('custom_note')->nullable();
        });
    }
};
PHP);
        $provider = $fixture->directory.'/app/Providers/FixtureProvider.php';
        $registration = "\$this->loadMigrationsFrom(\$this->app->basePath('app/provider-migrations'));";
        file_put_contents($provider, str_replace($registration, $registration." \$this->loadMigrationsFrom(\$this->app->basePath('custom-migrations'));", file_get_contents($provider)));
        // The consumer provider has already booted; mirror its new registration before comparison.
        $fixture->app->make('migrator')->path($directory);
        $before = $fixture->fingerprint();
        $comparison = $fixture->align->diff();

        expect($comparison['desired']['tables'][$fixture->schema.'.sample_records']['columns'])->toHaveKey('custom_note')
            ->and($comparison['source']['migration_names'])->toContain('2026_01_10_000000_custom_note')
            ->and($fixture->fingerprint())->toBe($before);
    } finally {
        $fixture->close();
    }
});

it('replays Storage namespace classes with private storage and without importing schema dumps', function (bool $customStorage): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $files = new Filesystem;
        $files->ensureDirectoryExists($fixture->directory.'/app/Services/Storage');
        $files->ensureDirectoryExists($fixture->directory.'/database/schema');
        file_put_contents($fixture->directory.'/app/Services/Storage/Columns.php', <<<'PHP'
<?php
namespace App\Services\Storage;
final class Columns {
    public static function name(): string { return 'storage_note'; }
}
PHP);
        $composer = json_decode(file_get_contents($fixture->directory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['autoload']['psr-4']['App\\'] = 'app/';
        file_put_contents($fixture->directory.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        (new Process(['composer', 'dump-autoload', '--no-scripts', '--no-plugins', '--no-interaction'], $fixture->directory, ['COMPOSER_DISABLE_NETWORK' => '1']))->mustRun();

        if ($customStorage) {
            $fixture->app->useStoragePath($fixture->directory.'/app/runtime');
            $files->ensureDirectoryExists($fixture->app->storagePath('logs'));
            file_put_contents($fixture->app->storagePath('logs/host.php'), '<?php return "runtime-sentinel";');
        }
        file_put_contents($fixture->directory.'/database/schema/pgsql-schema.sql', 'THIS UNUSED SCHEMA DUMP MUST NOT EXECUTE');
        file_put_contents($fixture->directory.'/database/migrations/2026_01_09_000000_storage_note.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string(\App\Services\Storage\Columns::name())->nullable();
        });
    }
};
PHP);
        $before = $fixture->fingerprint();
        $plan = $fixture->align->plan(['all-safe']);

        expect(array_column($plan['operations'], 'name'))->toContain('storage_note')
            ->and($fixture->fingerprint())->toBe($before);
        $fixture->align->apply($plan['id'], $plan['signature']);
        $fixture->pdo->exec('UPDATE '.$fixture->quotedSchema.".sample_records SET storage_note = 'Source was included' WHERE id = 1");

        expect($fixture->pdo->query('SELECT storage_note FROM '.$fixture->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('Source was included')
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
})->with([false, true]);

it('uses Storage namespace classes from an installed external path package', function (): void {
    if (getenv('SCHEMA_ALIGN_PATH_PACKAGE_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_PATH_PACKAGE_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new PathPackageFixture($this->app);

    try {
        (new Filesystem)->ensureDirectoryExists($fixture->packageDirectory.'/src/Storage');
        file_put_contents($fixture->packageDirectory.'/src/Storage/ReplayColumns.php', <<<'PHP'
<?php
namespace Korioinc\SchemaAlign\Storage;
final class ReplayColumns {
    public static function name(): string { return 'package_storage_note'; }
}
PHP);
        file_put_contents($fixture->packageDirectory.'/database/migrations/2026_01_09_000000_storage_note.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string(\Korioinc\SchemaAlign\Storage\ReplayColumns::name())->nullable();
        });
    }
};
PHP);
        $before = $fixture->consumer->fingerprint();
        $comparison = $fixture->consumer->align->diff();

        expect($comparison['desired']['tables'][$fixture->consumer->schema.'.sample_records']['columns'])->toHaveKey('package_storage_note')
            ->and($fixture->consumer->fingerprint())->toBe($before);
    } finally {
        $fixture->close();
    }
});
