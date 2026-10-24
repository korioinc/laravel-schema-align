<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\SchemaAlign;
use Korioinc\SchemaAlign\SchemaAlignServiceProvider;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class MySqlInstalledFixture
{
    public const TABLE = 'sa_mysql_installed_sample_items';

    public const LEDGER = 'sa_mysql_installed_migrations';

    public readonly PDO $pdo;

    public readonly string $directory;

    public readonly Application $app;

    public readonly SchemaAlign $align;

    private readonly Filesystem $files;

    /** @var resource */
    private mixed $databaseLock;

    public function __construct(private readonly Application $previousApp)
    {
        $dsn = getenv('SCHEMA_ALIGN_MYSQL_DSN');

        if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1' || ! is_string($dsn)
            || ! preg_match('/\Amysql:host=(127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=(schema-align-tests(?:-[a-z0-9]+)?)\z/D', $dsn, $parts)
            || (int) $parts[2] < 1 || (int) $parts[2] > 65535
            || getenv('SCHEMA_ALIGN_MYSQL_USER') !== 'schema_align_test') {
            throw new RuntimeException('Installed MySQL tests require an opted-in disposable loopback database and schema_align_test user.');
        }
        $lock = fopen(sys_get_temp_dir().'/schema-align-mysql-'.hash('sha256', $dsn).'.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot serialize installed MySQL fixtures.');
        }
        $this->databaseLock = $lock;
        $this->files = new Filesystem;
        $this->directory = sys_get_temp_dir().'/sa_mysql_installed_'.bin2hex(random_bytes(8));
        $this->pdo = new PDO($dsn, 'schema_align_test', getenv('SCHEMA_ALIGN_MYSQL_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);

        try {
            $this->stage();
            $this->app = require $this->directory.'/bootstrap/app.php';
            $this->app->make(Kernel::class)->bootstrap();
            $this->app->register(SchemaAlignServiceProvider::class);
            $config = $this->app->make('config');
            $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
            $config->set('app.url', 'http://localhost');
            $config->set('database.default', 'consumer_mysql');
            $config->set('database.connections.consumer_mysql', [
                'driver' => 'mysql', 'host' => $parts[1], 'port' => (int) $parts[2],
                'database' => $parts[3], 'username' => 'schema_align_test',
                'password' => getenv('SCHEMA_ALIGN_MYSQL_PASSWORD') ?: null,
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'prefix' => '',
            ]);
            $config->set('schema-align.engines.mysql.migration_table', self::LEDGER);
            $this->align = $this->app->make(SchemaAlign::class);
            $this->pdo->exec('CREATE TABLE `'.self::TABLE.'` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(255) NOT NULL) ENGINE=InnoDB');
            $this->pdo->exec('INSERT INTO `'.self::TABLE."` (name) VALUES ('Sample item A')");
            $this->pdo->exec('CREATE TABLE `'.self::LEDGER.'` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, migration varchar(255) NOT NULL, batch int NOT NULL) ENGINE=InnoDB');
            $this->pdo->exec('INSERT INTO `'.self::LEDGER."` (migration, batch) VALUES ('2026_01_01_000000_create_sample_items', 1)");
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }

    private function stage(): void
    {
        $root = dirname(__DIR__, 2);
        $this->files->copyDirectory($root.'/tests/Fixtures/replay/app', $this->directory);
        foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $path) {
            $this->files->ensureDirectoryExists($this->directory.'/'.$path);
        }
        foreach (['composer.json', 'composer.lock'] as $file) {
            $this->files->copy($root.'/'.$file, $this->directory.'/'.$file);
        }
        foreach (['src', 'resources'] as $path) {
            $this->files->copyDirectory($root.'/'.$path, $this->directory.'/'.$path);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/vendor', RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $destination = $this->directory.'/vendor/'.substr($entry->getPathname(), strlen($root.'/vendor/'));

            if ($entry->isDir()) {
                $this->files->ensureDirectoryExists($destination);
            } elseif (! $this->files->copy($entry->getRealPath(), $destination)) {
                // Hardlinks let another fixture's teardown change inode timestamps during capture.
                throw new RuntimeException('Cannot stage installed dependency file.');
            }
        }
        foreach (glob($this->directory.'/database/migrations/*.php') as $file) {
            unlink($file);
        }
        $this->files->put($this->directory.'/config/app.php', "<?php\nreturn ['name' => 'MySQL installed consumer', 'env' => 'testing', 'key' => env('APP_KEY'), 'debug' => false];\n");
        $this->files->put($this->directory.'/config/database.php', <<<'CONFIG'
<?php
return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => ['mysql' => [
        'driver' => 'mysql', 'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306), 'unix_socket' => env('DB_SOCKET', ''),
        'database' => env('DB_DATABASE'), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'),
        'charset' => env('DB_CHARSET', 'utf8mb4'), 'collation' => env('DB_COLLATION', 'utf8mb4_0900_ai_ci'),
        'prefix' => '',
    ]],
    'migrations' => ['table' => 'sa_mysql_installed_migrations'],
];
CONFIG);
        $this->writeMigration(false);
        // Original runtime files remain available for preservation checks after replay.
        $this->files->put($this->directory.'/.env', 'DB_PASSWORD="'.(getenv('SCHEMA_ALIGN_MYSQL_PASSWORD') ?: '').'"');
        $this->files->put($this->directory.'/storage/logs/host-secret.log', 'operational-storage-sentinel');
        $this->files->put($this->directory.'/bootstrap/cache/host-secret.php', '<?php return "host-only";');
    }

    public function writeMigration(bool $edited): void
    {
        $extra = $edited ? "            \$table->string('future_note')->nullable();\n" : '';
        $this->files->put($this->directory.'/database/migrations/2026_01_01_000000_create_sample_items.php', <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('sa_mysql_installed_sample_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('note')->nullable();
            $table->index('name', 'sa_mysql_installed_name_index');
MIGRATION
            ."\n".$extra."        });\n    }\n};\n");
    }

    public function fingerprint(): string
    {
        return (new MySqlCatalog($this->pdo, self::LEDGER))->inspect()->fingerprint();
    }

    public function itemName(): string
    {
        return (string) $this->pdo->query('SELECT name FROM `'.self::TABLE.'` WHERE id = 1')->fetchColumn();
    }

    public function close(): void
    {
        $this->pdo->exec('SET SESSION transaction_read_only = OFF');
        $this->pdo->exec('DROP TABLE IF EXISTS `'.self::TABLE.'`, `'.self::LEDGER.'`');
        $this->files->deleteDirectory($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        Application::setInstance($this->previousApp);
        flock($this->databaseLock, LOCK_UN);
        fclose($this->databaseLock);
    }
}
