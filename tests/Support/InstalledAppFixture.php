<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\SchemaAlign;
use Korioinc\SchemaAlign\SchemaAlignServiceProvider;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class InstalledAppFixture
{
    public readonly PDO $pdo;

    public readonly string $schema;

    public readonly string $quotedSchema;

    public readonly string $directory;

    public readonly Application $app;

    public readonly SchemaAlign $align;

    /** @var resource */
    private mixed $databaseLock;

    private readonly Filesystem $files;

    public function __construct(private readonly Application $previousApp)
    {
        $dsn = getenv('SCHEMA_ALIGN_TEST_DSN');

        if (! is_string($dsn) || ! preg_match('/\Apgsql:host=(127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=(schema-align-tests(?:-[a-z0-9]+)?)\z/D', $dsn, $parts)
            || (int) $parts[2] < 1 || (int) $parts[2] > 65535 || getenv('SCHEMA_ALIGN_TEST_USER') !== 'schema_align_test') {
            throw new RuntimeException('Installed app tests require the explicitly provisioned disposable PostgreSQL target.');
        }
        $this->databaseLock = fopen(sys_get_temp_dir().'/schema-align-tests-'.hash('sha256', $dsn).'.lock', 'c');

        if ($this->databaseLock === false || ! flock($this->databaseLock, LOCK_EX)) {
            throw new RuntimeException('Cannot serialize disposable PostgreSQL fixtures.');
        }
        $this->pdo = new PDO($dsn, 'schema_align_test', getenv('SCHEMA_ALIGN_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        if ((int) $this->pdo->query('SELECT rolsuper::int FROM pg_roles WHERE rolname = current_user')->fetchColumn() !== 0) {
            throw new RuntimeException('Installed app tests refuse superuser connections.');
        }
        $this->schema = 'sa_installed_'.bin2hex(random_bytes(8));
        $this->quotedSchema = '"'.$this->schema.'"';
        $this->directory = sys_get_temp_dir().'/'.$this->schema;
        $this->files = new Filesystem;
        $this->pdo->exec('CREATE SCHEMA '.$this->quotedSchema);

        try {
            $this->stage();
            $this->app = require $this->directory.'/bootstrap/app.php';
            $this->app->make(Kernel::class)->bootstrap();
            $this->app->register(SchemaAlignServiceProvider::class);
            $config = $this->app->make('config');
            $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
            $config->set('app.url', 'http://localhost');
            $config->set('database.default', 'installed_target');
            $config->set('database.connections.installed_target', [
                'driver' => 'pgsql', 'host' => $parts[1], 'port' => (int) $parts[2],
                'database' => $parts[3], 'username' => 'schema_align_test',
                'password' => getenv('SCHEMA_ALIGN_TEST_PASSWORD') ?: null,
                'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema, 'sslmode' => 'prefer',
            ]);
            // Preserve all first-use defaults: no connection, target identity or release settings.
            $config->set('schema-align.schemas', [$this->schema]);

            $this->align = new SchemaAlign($this->app);
            $this->pdo->exec('CREATE TABLE '.$this->quotedSchema.'.sample_records (id bigint NOT NULL, name varchar(255) NOT NULL)');
            $this->pdo->exec("INSERT INTO {$this->quotedSchema}.sample_records VALUES (1, 'Sample record A')");
            // A populated target ledger must never replace a fresh replay of current migration bytes.
            $this->pdo->exec('CREATE TABLE '.$this->quotedSchema.'.migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)');
            $this->pdo->exec("INSERT INTO {$this->quotedSchema}.migrations (migration, batch) VALUES ('2026_01_01_000000_create_sample_records', 1)");
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }

    private function stage(): void
    {
        $root = dirname(__DIR__, 2);
        $this->files->copyDirectory($root.'/tests/Fixtures/replay/app', $this->directory);
        foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs', 'app/Providers', 'app/provider-migrations'] as $path) {
            $this->files->ensureDirectoryExists($this->directory.'/'.$path);
        }
        foreach (['composer.json', 'composer.lock'] as $file) {
            $this->files->copy($root.'/'.$file, $this->directory.'/'.$file);
        }
        foreach (['src', 'resources'] as $path) {
            $this->files->copyDirectory($root.'/'.$path, $this->directory.'/'.$path);
        }
        // Independent copies keep concurrent fixtures from changing each other's inode timestamps.
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/vendor', RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $destination = $this->directory.'/vendor/'.substr($entry->getPathname(), strlen($root.'/vendor/'));

            if ($entry->isDir()) {
                $this->files->ensureDirectoryExists($destination);
            } elseif (! $this->files->copy($entry->getRealPath(), $destination)) {
                throw new RuntimeException('Cannot stage installed dependency file.');
            }
        }
        $this->files->put($this->directory.'/config/app.php', "<?php\nreturn ['name' => 'Installed app fixture', 'env' => 'testing', 'key' => env('APP_KEY'), 'url' => env('APP_URL', 'http://localhost'), 'debug' => false];\n");
        $this->files->put($this->directory.'/config/database.php', <<<'CONFIG'
<?php
return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => ['pgsql' => [
        'driver' => 'pgsql', 'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 5432), 'database' => env('DB_DATABASE'),
        'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'),
    ]],
    'migrations' => ['table' => 'migrations'],
];
CONFIG);
        $namespace = 'InstalledFixture'.substr($this->schema, 13);
        $this->files->put($this->directory.'/app/Providers/FixtureProvider.php', "<?php\nnamespace {$namespace};\nclass FixtureProvider extends \\Illuminate\\Support\\ServiceProvider { public function boot(): void { \$this->loadMigrationsFrom(\$this->app->basePath('app/provider-migrations')); } }\n");
        $this->files->put($this->directory.'/bootstrap/providers.php', "<?php\nrequire_once dirname(__DIR__).'/app/Providers/FixtureProvider.php';\nreturn [\\{$namespace}\\FixtureProvider::class];\n");
        $this->files->put($this->directory.'/app/provider-migrations/2026_01_02_000000_create_provider_notes.php', <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('provider_notes', function (Blueprint $table): void { $table->bigInteger('id'); });
    }
};
MIGRATION);
        $this->files->put($this->directory.'/database/migrations/2026_01_03_000000_create_credentials_table.php', <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('credentials', function (Blueprint $table): void { $table->bigInteger('id'); }); }
};
MIGRATION);
        $this->files->put($this->directory.'/.env', 'DB_PASSWORD="'.(getenv('SCHEMA_ALIGN_TEST_PASSWORD') ?: '').'"'."\nSCHEMA_ALIGN_HOST_SENTINEL=host-only-installed-app\n");
        $this->files->put($this->directory.'/storage/logs/host-secret.log', 'operational-storage-sentinel');
        $this->files->put($this->directory.'/bootstrap/cache/host-secret.php', '<?php return "host-only-installed-app";');
    }

    public function removeOptionalLabel(): void
    {
        $path = $this->directory.'/database/migrations/2026_01_01_000000_create_sample_records.php';
        $this->files->put($path, str_replace("            \$table->string('optional_label')->nullable();\n", '', $this->files->get($path)));
    }

    public function fingerprint(): string
    {
        return (new PostgresCatalog($this->pdo, [$this->schema]))->inspect()->fingerprint();
    }

    public function recordName(): string
    {
        return (string) $this->pdo->query('SELECT name FROM '.$this->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn();
    }

    public function close(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->exec('DROP SCHEMA '.$this->quotedSchema.' CASCADE');
        $this->files->deleteDirectory($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        Application::setInstance($this->previousApp);
        flock($this->databaseLock, LOCK_UN);
        fclose($this->databaseLock);
    }
}
