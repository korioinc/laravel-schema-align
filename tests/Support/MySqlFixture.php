<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Execution\MySqlApplyExecutor;
use Korioinc\SchemaAlign\Planning\PlanStore;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Snapshot;
use PDO;
use RuntimeException;

final class MySqlFixture
{
    public readonly PDO $pdo;

    public readonly string $table;

    public readonly string $directory;

    public readonly PlanStore $store;

    /** @var array<string, mixed> */
    public readonly array $binding;

    /** @var resource */
    private mixed $databaseLock;

    public function __construct()
    {
        $dsn = getenv('SCHEMA_ALIGN_MYSQL_DSN');

        if (! is_string($dsn) || ! preg_match('/\Amysql:host=(127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=(schema-align-tests(?:-[a-z0-9]+)?)\z/D', $dsn, $parts)
            || (int) $parts[2] < 1 || (int) $parts[2] > 65535 || getenv('SCHEMA_ALIGN_MYSQL_USER') !== 'schema_align_test') {
            throw new RuntimeException('MySQL tests require the explicitly provisioned disposable local target.');
        }
        $handle = fopen(sys_get_temp_dir().'/schema-align-mysql-'.hash('sha256', $dsn).'.lock', 'c');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot serialize disposable MySQL fixtures.');
        }
        $this->databaseLock = $handle;
        $this->pdo = new PDO($dsn, 'schema_align_test', getenv('SCHEMA_ALIGN_MYSQL_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
        $this->table = 'sa_apply_'.bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir().'/'.$this->table;
        $this->store = new PlanStore($this->directory);
        $this->binding = ['driver' => 'mysql', 'host' => $parts[1], 'port' => $parts[2], 'database' => $parts[3], 'username' => 'schema_align_test', 'instance' => 'isolated-tests'];
        $this->pdo->exec('CREATE TABLE `'.$this->table.'` (id int NOT NULL PRIMARY KEY, name varchar(100) NOT NULL) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO `{$this->table}` VALUES (1, 'Sample item A'), (2, 'Sample item B')");
    }

    /** @return array<string, mixed> */
    public function plan(bool $twoSteps = false): array
    {
        $before = $this->inspect();
        $firstSql = 'ALTER TABLE `'.$this->table.'` ADD COLUMN note text NULL';
        $this->pdo->exec($firstSql);
        $first = $this->inspect();
        $operations = [$this->operation($firstSql, $before, $first)];
        $after = $first;

        if ($twoSteps) {
            $sql = 'CREATE UNIQUE INDEX sa_unique_name ON `'.$this->table.'` (name)';
            $this->pdo->exec($sql);
            $after = $this->inspect();
            $operations[] = $this->operation($sql, $first, $after);
            $this->pdo->exec('DROP INDEX sa_unique_name ON `'.$this->table.'`');
        }
        $this->pdo->exec('ALTER TABLE `'.$this->table.'` DROP COLUMN note');

        return $this->store->save([
            'engine' => 'mysql', 'execution_mode' => 'checkpointed', 'schemas' => ['@database'],
            'migration_table' => 'migrations', 'source' => ['revision' => 'isolated-apply-fixture'],
            'binding' => $this->binding, 'identity' => $before->identity,
            'before_hash' => $before->fingerprint(), 'after_hash' => $after->fingerprint(), 'operations' => $operations,
        ]);
    }

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function apply(array $plan, bool $recover = false): array
    {
        return (new MySqlApplyExecutor($this->pdo, $this->store))->apply($plan['id'], $plan['signature'], $this->binding, $recover, 1000, 0);
    }

    public function inspect(): Snapshot
    {
        return (new MySqlCatalog($this->pdo))->inspect();
    }

    public function close(): void
    {
        $this->pdo->exec('SET SESSION transaction_read_only = OFF');
        $this->pdo->exec('DROP TABLE `'.$this->table.'`');
        (new Filesystem)->deleteDirectory($this->directory);
        flock($this->databaseLock, LOCK_UN);
        fclose($this->databaseLock);
    }

    /** @return array<string, mixed> */
    private function operation(string $sql, Snapshot $before, Snapshot $after): array
    {
        return ['automatic' => true, 'transactional' => false, 'sql' => [$sql], 'before_hash' => $before->fingerprint(), 'after_hash' => $after->fingerprint()];
    }
}
