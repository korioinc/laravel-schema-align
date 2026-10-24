<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\SchemaAlign;
use PDO;
use RuntimeException;
use Throwable;

final class ApplyFixture
{
    public readonly PDO $pdo;

    public readonly string $schema;

    public readonly string $quotedSchema;

    public readonly string $directory;

    public readonly SchemaAlign $align;

    /** @var resource */
    private mixed $databaseLock;

    public function __construct(private readonly Application $app)
    {
        $dsn = getenv('SCHEMA_ALIGN_TEST_DSN');

        if (! is_string($dsn) || ! preg_match('/\Apgsql:host=(127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=(schema-align-tests(?:-[a-z0-9]+)?)\z/D', $dsn, $parts)
            || (int) $parts[2] < 1 || (int) $parts[2] > 65535 || getenv('SCHEMA_ALIGN_TEST_USER') !== 'schema_align_test') {
            throw new RuntimeException('Apply tests require the explicitly provisioned disposable PostgreSQL target.');
        }
        // The production advisory lock is database-wide; serialize integration fixtures across Pest workers.
        $this->databaseLock = fopen(sys_get_temp_dir().'/schema-align-tests-'.hash('sha256', $dsn).'.lock', 'c');

        if ($this->databaseLock === false || ! flock($this->databaseLock, LOCK_EX)) {
            throw new RuntimeException('Cannot serialize disposable PostgreSQL apply fixtures.');
        }
        $this->pdo = new PDO($dsn, 'schema_align_test', getenv('SCHEMA_ALIGN_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        if ($this->pdo->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn()) {
            throw new RuntimeException('Apply tests refuse superuser connections.');
        }
        $this->schema = 'sa_apply_'.bin2hex(random_bytes(8));
        $this->quotedSchema = '"'.$this->schema.'"';
        $this->directory = sys_get_temp_dir().'/'.$this->schema;
        $connection = [
            'driver' => 'pgsql', 'host' => $parts[1], 'port' => (int) $parts[2],
            'database' => $parts[3], 'username' => 'schema_align_test',
            'password' => getenv('SCHEMA_ALIGN_TEST_PASSWORD') ?: null,
            'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'prefer',
        ];
        $app->make('config')->set('database.connections.schema_align_testing', $connection);
        $app->make('config')->set('schema-align.connection', 'schema_align_testing');
        $app->make('config')->set('schema-align.target_instance', 'isolated-tests');
        $app->make('config')->set('schema-align.schemas', [$this->schema]);
        $app->make('config')->set('schema-align.migration_table', 'migrations');
        $app->make('config')->set('schema-align.plan_directory', $this->directory);
        $this->align = $app->make(SchemaAlign::class);
        $this->pdo->exec('CREATE SCHEMA '.$this->quotedSchema);

        try {
            $this->pdo->exec('CREATE TABLE '.$this->quotedSchema.'.sample_items (id integer PRIMARY KEY, name text NOT NULL)');
            $this->pdo->exec("INSERT INTO {$this->quotedSchema}.sample_items VALUES (1, 'Sample item A')");
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function plan(): array
    {
        $catalog = new PostgresCatalog($this->pdo, [$this->schema]);
        $this->pdo->exec('ALTER TABLE '.$this->quotedSchema.'.sample_items ADD COLUMN note text');
        $desired = $catalog->inspect();
        // Restore only this fixture's temporary addition before pinning the real baseline.
        $this->pdo->exec('ALTER TABLE '.$this->quotedSchema.'.sample_items DROP COLUMN note');
        $actual = $catalog->inspect();
        $comparison = (new SchemaComparator)->compare($desired, $actual);
        $comparison['desired'] = $desired->toArray();
        $comparison['actual'] = $actual->toArray();
        $comparison['source'] = ['revision' => 'isolated-apply-fixture'];
        $comparison['binding'] = TargetConnection::binding(
            $this->app->make('config')->get('database.connections.schema_align_testing'),
            'isolated-tests', $this->app->environment(),
        );

        return $this->align->pin($comparison, ['all-safe']);
    }

    public function fingerprint(): string
    {
        return (new PostgresCatalog($this->pdo, [$this->schema]))->inspect()->fingerprint();
    }

    public function itemName(): string
    {
        return (string) $this->pdo->query('SELECT name FROM '.$this->quotedSchema.'.sample_items WHERE id = 1')->fetchColumn();
    }

    public function close(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->exec('DROP SCHEMA '.$this->quotedSchema.' CASCADE');
        (new Filesystem)->deleteDirectory($this->directory);
        flock($this->databaseLock, LOCK_UN);
        fclose($this->databaseLock);
    }
}
