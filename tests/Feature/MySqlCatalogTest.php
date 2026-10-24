<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\MySqlComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;
use Korioinc\SchemaAlign\Tests\Support\MySqlFixture;

it('requires a MySQL PDO connection', function (): void {
    (new MySqlCatalog(new PDO('sqlite::memory:')))->inspect();
})->throws(RuntimeException::class, 'PDO MySQL');

it('distinguishes MySQL TABLESPACE options from quoted schema content', function (string $mode): void {
    if (! getenv('SCHEMA_ALIGN_MYSQL_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_DSN for real MySQL catalog verification.');
    }
    $fixture = new MySqlFixture;

    try {
        $pdo = $fixture->pdo;
        $pdo->exec('SET SESSION sql_mode = '.$pdo->quote($mode));
        $before = $fixture->inspect();
        $columns = [
            'literal_note' => 'TABLESPACE archive',
            'TABLESPACE archive' => "quoted' ) TABLESPACE archive",
            'quoted` TABLESPACE archive' => "backslash\\' TABLESPACE archive",
            'double" TABLESPACE archive' => 'parentheses ( ) TABLESPACE archive',
            'ending\\' => 'trailing\\',
            'tablespace' => 'ordinary column',
        ];
        foreach ($columns as $name => $default) {
            $pdo->exec('ALTER TABLE `'.$fixture->table.'` ADD COLUMN `'.str_replace('`', '``', $name).'` varchar(100) NOT NULL DEFAULT '.$pdo->quote($default));
        }
        $desired = $fixture->inspect();
        $comparison = (new MySqlComparator)->compare($desired, $before);
        expect($comparison['errors'])->toBe([])
            ->and($comparison['differences'])->not->toBeEmpty();

        $pdo->exec('ALTER TABLE `'.$fixture->table.'` TABLESPACE innodb_system');
        expect(array_column($fixture->inspect()->unsupported, 'reason'))->toContain('tablespace');
    } finally {
        $fixture->close();
    }
})->with(['', 'NO_BACKSLASH_ESCAPES']);

it('rejects unsupported server versions and products before starting inspection', function (string $version, string $product): void {
    $pdo = new class($version, $product) extends PDO
    {
        public function __construct(private string $version, private string $product)
        {
            parent::__construct('sqlite::memory:');
        }

        public function getAttribute(int $attribute): mixed
        {
            return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if ($query !== 'SELECT VERSION() AS version, @@version_comment AS product') {
                throw new LogicException('Unsupported server inspection continued.');
            }

            return parent::prepare('SELECT '.$this->quote($this->version).' AS version, '.$this->quote($this->product).' AS product', $options);
        }
    };
    expect(fn () => (new MySqlCatalog($pdo))->inspect())->toThrow(RuntimeException::class, 'Only genuine MySQL');
    expect($pdo->inTransaction())->toBeFalse();
})->with([
    ['8.0.15', 'MySQL Community Server - GPL'],
    ['8.0.19', 'MySQL Community Server - GPL'],
    ['8.1.0', 'MySQL Community Server - GPL'],
    ['9.0.0', 'MySQL Community Server - GPL'],
    ['10.11.0-MariaDB', 'MariaDB Server'],
    ['8.0.36', 'Percona Server (GPL)'],
    ['8.4.11', 'Unknown compatible server'],
]);

it('reads real MySQL metadata without hashing data or taking caller transactions', function (): void {
    $dsn = getenv('SCHEMA_ALIGN_MYSQL_DSN');

    if (! $dsn) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_DSN for real MySQL catalog verification.');
    }

    if (! preg_match('/\Amysql:host=(?:127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=schema-align-tests(?:-[a-z0-9]+)?(?:;charset=utf8mb4)?\z/D', $dsn, $match)
        || (int) $match[1] < 1 || (int) $match[1] > 65535 || getenv('SCHEMA_ALIGN_MYSQL_USER') !== 'schema_align_test') {
        throw new RuntimeException('MySQL tests require an explicit loopback disposable schema-align-tests database and schema_align_test user.');
    }
    $pdo = new PDO($dsn, getenv('SCHEMA_ALIGN_MYSQL_USER'), getenv('SCHEMA_ALIGN_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lock = fopen(sys_get_temp_dir().'/schema-align-mysql-'.hash('sha256', $dsn).'.lock', 'c');

    if ($lock === false || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot acquire MySQL fixture lock.');
    }
    $prefix = 'sa_catalog_'.bin2hex(random_bytes(5));
    $parent = $prefix.'_parents';
    $child = $prefix.'_items';
    $ledger = $prefix.'_migrations';

    try {
        $pdo->exec("CREATE TABLE `$ledger` (id int unsigned AUTO_INCREMENT PRIMARY KEY, migration varchar(255) NOT NULL, batch int NOT NULL) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO `$ledger` (migration,batch) VALUES ('example',1)");
        $pdo->exec("CREATE TABLE `$parent` (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, code varchar(31), UNIQUE KEY code_unique (code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
        $pdo->exec("CREATE TABLE `$child` (id int PRIMARY KEY, parent_id bigint unsigned, price decimal(12,3) DEFAULT 1.250, title varchar(47) COLLATE utf8mb4_bin, total decimal(12,3) GENERATED ALWAYS AS (price * 2) STORED, stamp timestamp(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), CONSTRAINT `{$prefix}_fk` FOREIGN KEY (parent_id) REFERENCES `$parent` (id) ON DELETE SET NULL, CONSTRAINT `{$prefix}_check` CHECK (price > 0), KEY title_prefix (title(12) DESC) INVISIBLE, KEY expr_index ((lower(title)))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
        $catalog = new MySqlCatalog($pdo, $ledger);
        $snapshot = $catalog->inspect();
        $table = $snapshot->tables['@database.'.$child];
        expect($snapshot->unsupported)->toBe([])
            ->and($snapshot->migrationNames)->toBe(['example'])
            ->and($snapshot->platform['engine'])->toBe('mysql')
            ->and($snapshot->platform['settings']['sql_generate_invisible_primary_key'])->toBe(0)
            ->and($pdo->inTransaction())->toBeFalse()
            ->and($table['columns']['price']['type'])->toBe('decimal(12,3)')
            ->and($table['columns']['total']['generated'])->toBe('s')
            ->and($table['columns']['stamp']['default_kind'])->toBe('expression')
            ->and($table['columns']['stamp']['on_update'])->toBe('CURRENT_TIMESTAMP(3)')
            ->and($table['columns']['title']['collation'])->toBe('utf8mb4_bin')
            ->and($table['indexes']['title_prefix']['invisible'])->toBeTrue()
            ->and($table['indexes']['title_prefix']['keys'][0]['prefix'])->toBe(12)
            ->and($table['indexes']['title_prefix']['keys'][0]['order'])->toBe('DESC')
            ->and($table['indexes']['expr_index']['keys'][0]['expression'])->not->toBeNull()
            ->and($table['constraints'][$prefix.'_fk']['foreign_schema'])->toBe('@database')
            ->and(array_column($snapshot->tables['@database.'.$parent]['constraints'], 'kind'))->toBe(['p'])
            ->and(Snapshot::fromArray($snapshot->toArray())->fingerprint())->toBe($snapshot->fingerprint());
        $pdo->exec("CREATE TABLE `{$prefix}_pk_prefix` (code varchar(20) NOT NULL, PRIMARY KEY(code(10))) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE `{$prefix}_fulltext` (body text, FULLTEXT KEY body_search(body)) ENGINE=InnoDB");
        $unsupportedComparison = (new MySqlComparator)->compare($catalog->inspect(), $catalog->inspect());
        expect($unsupportedComparison['errors'])->not->toBe([])
            ->and(array_column($catalog->inspect()->unsupported, 'reason'))->toContain('primary_key_properties', 'fulltext_parser');
        $pdo->exec("DROP TABLE `{$prefix}_pk_prefix`, `{$prefix}_fulltext`");
        $pdo->exec("INSERT INTO `$parent` (code) VALUES ('one')");
        expect($catalog->inspect()->fingerprint())->toBe($snapshot->fingerprint());
        $pdo->beginTransaction();
        $catalog->inspect();
        expect($pdo->inTransaction())->toBeTrue();
        $pdo->rollBack();
        $pdo->exec("ALTER TABLE `$child` RENAME INDEX title_prefix TO title_renamed");
        expect($catalog->inspect()->tables['@database.'.$child]['indexes']['title_renamed']['signature'])->toBe($table['indexes']['title_prefix']['signature']);
        $pdo->exec("CREATE TABLE `{$prefix}_partition` (id int PRIMARY KEY) ENGINE=InnoDB PARTITION BY HASH(id) PARTITIONS 2");
        $pdo->exec("CREATE TABLE `{$prefix}_legacy` (id int, hidden int INVISIBLE) ENGINE=MyISAM COMMENT='manual table'");
        $adminPassword = getenv('SCHEMA_ALIGN_MYSQL_FIXTURE_ADMIN_PASSWORD');

        if ($adminPassword !== false && $adminPassword !== '') {
            // Same guarded loopback DSN; this connection only creates an owned fixture.
            $admin = new PDO($dsn, 'root', $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $admin->exec("CREATE TRIGGER `{$prefix}_trigger` BEFORE INSERT ON `$child` FOR EACH ROW SET NEW.id = NEW.id");
        }
        $pdo->exec("CREATE PROCEDURE `{$prefix}_routine`() SELECT 1");
        $pdo->exec("CREATE EVENT `{$prefix}_event` ON SCHEDULE EVERY 1 DAY DISABLE DO SELECT 1");
        $reasons = array_column($catalog->inspect()->unsupported, 'reason');
        expect($reasons)->toContain('partition', 'table_properties', 'column_properties', 'routine', 'event');

        if ($adminPassword !== false && $adminPassword !== '') {
            expect($reasons)->toContain('trigger');
        }
        $pdo->exec("CREATE VIEW `{$prefix}_view` AS SELECT id FROM `$child`");
        expect(array_column($catalog->inspect()->unsupported, 'reason'))->toContain('view');
        $pdo->exec("ALTER TABLE `$ledger` DROP COLUMN batch");
        expect(fn () => $catalog->inspect())->toThrow(RuntimeException::class, 'missing required columns');
        expect($pdo->inTransaction())->toBeFalse();
        $pdo->beginTransaction();
        expect(fn () => $catalog->inspect())->toThrow(RuntimeException::class, 'missing required columns');
        expect($pdo->inTransaction())->toBeTrue();
        $pdo->rollBack();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("DROP VIEW IF EXISTS `{$prefix}_view`");
        $pdo->exec("DROP EVENT IF EXISTS `{$prefix}_event`");
        $pdo->exec("DROP PROCEDURE IF EXISTS `{$prefix}_routine`");
        foreach ([$child, $parent, $ledger, $prefix.'_partition', $prefix.'_legacy', $prefix.'_pk_prefix', $prefix.'_fulltext'] as $name) {
            $pdo->exec("DROP TABLE IF EXISTS `$name`");
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});
