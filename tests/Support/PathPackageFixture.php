<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class PathPackageFixture
{
    public const DEVELOPMENT_PACKAGE = 'schema-align-fixture/development-only';

    public readonly InstalledAppFixture $consumer;

    public readonly string $packageDirectory;

    public readonly string $installedPath;

    private readonly Filesystem $files;

    public function __construct(Application $previousApp, bool $insideApp = false, bool $symlink = true, ?string $repositoryEnvironmentVariable = null)
    {
        $this->files = new Filesystem;
        $this->consumer = new InstalledAppFixture($previousApp);
        $packageDirectory = $this->consumer->directory.($insideApp ? '/packages/schema-align' : '-packages/schema-align');
        $this->files->ensureDirectoryExists($packageDirectory);
        $this->packageDirectory = realpath($packageDirectory);
        $this->installedPath = $this->consumer->directory.'/vendor/korioinc/laravel-schema-align';

        try {
            $this->install($symlink, $repositoryEnvironmentVariable);
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }

    private function install(bool $symlink, ?string $repositoryEnvironmentVariable): void
    {
        $root = dirname(__DIR__, 2);
        $this->files->ensureDirectoryExists($this->packageDirectory);
        foreach (['src', 'config', 'resources'] as $path) {
            $this->files->copyDirectory($root.'/'.$path, $this->packageDirectory.'/'.$path);
        }
        $this->files->ensureDirectoryExists($this->packageDirectory.'/database/migrations');
        $this->files->put($this->packageDirectory.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/package-decoy/database.sqlite\nPATH_PACKAGE_SECRET=package-env-must-not-reach-replay\n");
        $this->files->put(dirname($this->packageDirectory).'/sibling-secret', 'sibling-must-not-reach-replay');
        $this->files->put($this->migrationPath(), $this->migration());
        $this->files->put($this->packageDirectory.'/config/path-fixture.php', "<?php\nreturn ['migration_path' => dirname(__DIR__).'/database/migrations'];\n");
        $provider = 'PathFixture'.substr($this->consumer->schema, 13).'ServiceProvider';
        $this->files->put($this->packageDirectory.'/src/'.$provider.'.php', "<?php\nnamespace Korioinc\\SchemaAlign;\nclass {$provider} extends SchemaAlignServiceProvider { public function register(): void { parent::register(); \$this->mergeConfigFrom(dirname(__DIR__).'/config/path-fixture.php', 'path-fixture'); } public function boot(): void { parent::boot(); \$this->loadMigrationsFrom(config('path-fixture.migration_path')); } }\n");
        $package = json_decode($this->files->get($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $package['extra']['laravel']['providers'][] = 'Korioinc\\SchemaAlign\\'.$provider;
        $this->writeJson($this->packageDirectory.'/composer.json', $package);

        $appDirectory = $this->consumer->directory;
        $composer = json_decode($this->files->get($appDirectory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['name'] = 'schema-align-fixture/consumer';
        $composer['require']['korioinc/laravel-schema-align'] = '@dev';
        unset($composer['autoload'], $composer['autoload-dev'], $composer['scripts']);
        $composer['repositories'] = [
            ['type' => 'path', 'url' => $repositoryEnvironmentVariable === null ? $this->packageDirectory : '$'.$repositoryEnvironmentVariable, 'options' => ['symlink' => $symlink, 'versions' => ['korioinc/laravel-schema-align' => 'dev-main']]],
            ['packagist.org' => false],
        ];
        $this->writeJson($appDirectory.'/composer.json', $composer);

        // The installed fixture hardlinks vendor files. Composer must own its metadata before rewriting it.
        foreach ($this->files->files($appDirectory.'/vendor/composer') as $file) {
            $contents = $this->files->get($file->getPathname());
            $this->files->delete($file->getPathname());
            $this->files->put($file->getPathname(), $contents);
        }
        $autoload = $this->files->get($appDirectory.'/vendor/autoload.php');
        $this->files->delete($appDirectory.'/vendor/autoload.php');
        $this->files->put($appDirectory.'/vendor/autoload.php', $autoload);
        // A partial update retains every locked dependency and installs only our local path package.
        $environment = [
            'COMPOSER_DISABLE_NETWORK' => '1',
        ];

        if ($repositoryEnvironmentVariable !== null) {
            $environment[$repositoryEnvironmentVariable] = $this->packageDirectory;
        }
        (new Process(['composer', 'update', 'korioinc/laravel-schema-align', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-audit', '--minimal-changes'], $appDirectory, $environment, timeout: 120))->mustRun();

        if ($symlink && (! is_link($this->installedPath) || realpath($this->installedPath) !== $this->packageDirectory)) {
            throw new RuntimeException('Composer did not install the package as a path symlink.');
        }

        if (! $symlink && (! is_dir($this->installedPath) || is_link($this->installedPath) || realpath($this->installedPath) === $this->packageDirectory)) {
            throw new RuntimeException('Composer did not install the package as a path copy.');
        }
        // The consumer was bootstrapped before installation; register the newly installed provider there too.
        require $this->installedPath.'/src/'.$provider.'.php';
        $this->consumer->app->register('Korioinc\\SchemaAlign\\'.$provider);
    }

    public function removeDevelopmentDependencies(): void
    {
        $appDirectory = $this->consumer->directory;
        $developmentDirectory = dirname($this->packageDirectory).'/development-only';
        $this->files->ensureDirectoryExists($developmentDirectory);
        $this->writeJson($developmentDirectory.'/composer.json', [
            'name' => self::DEVELOPMENT_PACKAGE,
            'version' => 'dev-main',
            'autoload' => ['files' => ['autoload.php']],
        ]);
        $this->files->put($developmentDirectory.'/autoload.php', '<?php throw new \\RuntimeException("An uninstalled development path package was replayed.");');
        $composer = json_decode($this->files->get($appDirectory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode($this->files->get($appDirectory.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $framework = collect([...$lock['packages'], ...$lock['packages-dev']])->firstWhere('name', 'laravel/framework');
        // A production Laravel consumer keeps the framework when Composer removes test tooling.
        $composer['require']['laravel/framework'] = $framework['version'];
        $composer['require-dev'][self::DEVELOPMENT_PACKAGE] = '@dev';
        array_unshift($composer['repositories'], ['type' => 'path', 'url' => $developmentDirectory]);
        $this->writeJson($appDirectory.'/composer.json', $composer);
        (new Process(['composer', 'update', self::DEVELOPMENT_PACKAGE, '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-audit', '--minimal-changes'], $appDirectory, [
            'COMPOSER_DISABLE_NETWORK' => '1',
        ], timeout: 120))->mustRun();
        (new Process(['composer', 'install', '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress'], $appDirectory, [
            'COMPOSER_DISABLE_NETWORK' => '1',
        ], timeout: 120))->mustRun();
    }

    public function addPackageColumn(bool $installed = false): void
    {
        $this->files->put($this->migrationPath($installed), str_replace("\$table->bigInteger('id');", "\$table->bigInteger('id'); \$table->string('label')->nullable();", $this->migration()));
    }

    private function migrationPath(bool $installed = false): string
    {
        return ($installed ? $this->installedPath : $this->packageDirectory).'/database/migrations/2026_01_04_000000_create_path_package_notes.php';
    }

    private function migration(): string
    {
        return <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('path_package_notes', function (Blueprint $table): void { $table->bigInteger('id'); });
    }
};
PHP;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $this->files->put($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    public function close(): void
    {
        $this->files->deleteDirectory(dirname($this->packageDirectory));
        $this->consumer->close();
    }
}
