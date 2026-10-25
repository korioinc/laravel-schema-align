<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\MySqlComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;
use Korioinc\SchemaAlign\SchemaAlign;
use Korioinc\SchemaAlign\Tests\Support\MySqlFixture;

beforeEach(function (): void {
    if (! getenv('SCHEMA_ALIGN_MYSQL_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_DSN for isolated MySQL DDL safety tests.');
    }
    $this->mysqlFixture = new MySqlFixture;
    $this->mysqlTables = [];
    $this->app->make('config')->set('database.connections.ddl_safety', ['driver' => 'mysql']);
    $this->app->make('config')->set('schema-align.connection', 'ddl_safety');
    $this->app->make('config')->set('schema-align.target_instance', 'isolated-tests');
    $this->app->make('config')->set('schema-align.plan_directory', $this->mysqlFixture->directory);
    $this->mysqlAlign = new SchemaAlign($this->app);
});

afterEach(function (): void {
    if (isset($this->mysqlFixture)) {
        $this->mysqlFixture->pdo->exec('SET SESSION transaction_read_only = OFF');
        foreach ($this->mysqlTables as $table) {
            $this->mysqlFixture->pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
        }
        $this->mysqlFixture->close();
    }
});

/** @return array<string, mixed> */
function mysqlDdlComparison(MySqlFixture $fixture, Snapshot $desired, Snapshot $actual): array
{
    return [...(new MySqlComparator)->compare($desired, $actual), 'actual' => $actual->toArray(),
        'binding' => $fixture->binding, 'source' => ['revision' => 'ddl-safety-fixture']];
}

it('rejects pinned MySQL DDL when generated invisible primary keys are enabled after approval', function (): void {
    if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1'
        || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER') || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD')) {
        $this->markTestSkipped('Explicit disposable administrator credentials are required for changing GIPK mode.');
    }
    $f = $this->mysqlFixture;

    if ($f->pdo->query("SHOW SESSION VARIABLES WHERE Variable_name = 'sql_generate_invisible_primary_key'")->fetchAll() === []) {
        $this->markTestSkipped('MySQL before 8.0.30 does not support generated invisible primary keys.');
    }
    $connection = array_replace($f->binding, [
        'username' => getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'), 'password' => getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'),
        'options' => [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION sql_generate_invisible_primary_key = OFF'],
    ]);
    $config = $this->app->make('config');
    $config->set('database.connections.ddl_safety', $connection);
    $pdo = TargetConnection::writable($connection, 'isolated-tests');
    $catalog = new MySqlCatalog($pdo);
    $table = $f->table.'_without_pk';
    $this->mysqlTables[] = $table;
    $pdo->exec('CREATE TABLE `'.$table.'` (note varchar(100) NULL) ENGINE=InnoDB');
    $desired = $catalog->inspect();
    $pdo->exec('DROP TABLE `'.$table.'`');
    $before = $catalog->inspect();
    $comparison = mysqlDdlComparison($f, $desired, $before);
    $comparison['binding'] = TargetConnection::binding($connection, 'isolated-tests', $this->app->environment());
    $plan = $this->mysqlAlign->pin($comparison, ['all-supported']);
    $journal = $f->store->status($plan['id']);
    $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    expect($this->mysqlAlign->reviewPlan($plan['id'])['status'])->toBe('pending');
    $config->set('database.connections.ddl_safety.options', [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION sql_generate_invisible_primary_key = ON']);

    expect(fn () => $this->mysqlAlign->reviewPlan($plan['id']))->toThrow(RuntimeException::class, 'Stale or divergent MySQL plan');
    expect(fn () => $this->mysqlAlign->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class, 'Stale or divergent MySQL plan')
        ->and($catalog->inspect()->fingerprint())->toBe($before->fingerprint())
        ->and($f->store->status($plan['id']))->toBe($journal)
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);

    $config->set('database.connections.ddl_safety', $connection);
    expect($this->mysqlAlign->apply($plan['id'], $plan['signature'])['status'])->toBe('applied');
    $actual = $catalog->inspect();
    expect($actual->tables['@database.'.$table]['columns'])->toHaveKeys(['note'])->not->toHaveKey('my_row_id')
        ->and($actual->tables['@database.'.$table]['constraints'])->toBe([])
        ->and($actual->fingerprint())->toBe($plan['after_hash']);
});

it('keeps newly created MySQL tables usable across apply and recovery', function (string $format): void {
    $f = $this->mysqlFixture;
    $table = $f->table.'_new';
    $this->mysqlTables[] = $table;
    $rowFormat = $format === 'DYNAMIC' ? '' : ' ROW_FORMAT='.$format;
    $f->pdo->exec('CREATE TABLE `'.$table.'` (id int PRIMARY KEY, note varchar(100) NULL) ENGINE=InnoDB'.$rowFormat);
    $desired = $f->inspect();
    $f->pdo->exec('DROP TABLE `'.$table.'`');
    $before = $f->inspect();
    $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $plan = $this->mysqlAlign->pin(mysqlDdlComparison($f, $desired, $before), ['all-supported']);
    $f->apply($plan);
    $f->pdo->exec('SET SESSION transaction_read_only = OFF');
    $f->pdo->exec("INSERT INTO `$table` VALUES (1, 'retained through recovery')");
    // Lose the final journal update after committed DDL, then reconcile the real catalog.
    $f->store->record($plan['id'], ['status' => 'executing', 'operation_index' => 0]);
    $f->apply($plan, true);

    expect($f->pdo->query("SELECT note FROM `$table` WHERE id = 1")->fetchColumn())->toBe('retained through recovery')
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
})->with(['DYNAMIC', 'COMPACT', 'REDUNDANT']);

it('refuses automatic plans when supported row formats include unsupported table options', function (): void {
    $f = $this->mysqlFixture;
    $before = $f->inspect();
    $f->pdo->exec('ALTER TABLE `'.$f->table.'` ROW_FORMAT=DYNAMIC STATS_PERSISTENT=0');
    $actual = $f->inspect();
    $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    expect(fn () => $this->mysqlAlign->pin(mysqlDdlComparison($f, $before, $actual), ['all-supported']))->toThrow(RuntimeException::class)
        ->and($f->inspect()->fingerprint())->toBe($actual->fingerprint())
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
});

it('preserves existing foreign key indexes when new indexes could replace them', function (bool $dropForeignKey): void {
    $f = $this->mysqlFixture;
    $child = $f->table.'_child';
    $foreign = $child.'_fk';
    $this->mysqlTables[] = $child;
    $create = 'CREATE TABLE `'.$child.'` (id int PRIMARY KEY, parent_id int NOT NULL, note varchar(100), CONSTRAINT `'.$foreign.'` FOREIGN KEY (parent_id) REFERENCES `'.$f->table.'` (id)) ENGINE=InnoDB';
    $f->pdo->exec($create);

    if ($dropForeignKey) {
        $f->pdo->exec('ALTER TABLE `'.$child.'` DROP FOREIGN KEY `'.$foreign.'`');
    }
    $f->pdo->exec("INSERT INTO `$child` VALUES (1, 1, 'retained child')");
    $before = $f->inspect();
    $f->pdo->exec('CREATE INDEX parent_note ON `'.$child.'` (parent_id, note)');
    $desired = $f->inspect();
    $f->pdo->exec('DROP TABLE `'.$child.'`');
    $f->pdo->exec($create);

    if ($dropForeignKey) {
        $f->pdo->exec('ALTER TABLE `'.$child.'` DROP FOREIGN KEY `'.$foreign.'`');
    }
    $f->pdo->exec("INSERT INTO `$child` VALUES (1, 1, 'retained child')");
    $comparison = mysqlDdlComparison($f, $desired, $before);
    $operation = array_values(array_filter($comparison['differences'], fn (array $operation): bool => $operation['kind'] === 'create_index'))[0];

    expect(fn () => $this->mysqlAlign->pin($comparison, [$operation['id']]))->toThrow(RuntimeException::class)
        ->and($f->inspect()->fingerprint())->toBe($before->fingerprint())
        ->and($f->pdo->query("SELECT note FROM `$child` FORCE INDEX (`$foreign`) WHERE parent_id = 1")->fetchColumn())->toBe('retained child');

    if (! $dropForeignKey) {
        expect(fn () => $f->pdo->exec("INSERT INTO `$child` VALUES (2, 999, 'orphan')"))->toThrow(PDOException::class);
    }
})->with(['active foreign key' => false, 'removed foreign key' => true]);

it('applies unrelated indexes without disturbing foreign keys or existing rows', function (): void {
    $f = $this->mysqlFixture;
    $child = $f->table.'_child';
    $foreign = $child.'_fk';
    $this->mysqlTables[] = $child;
    $f->pdo->exec('CREATE TABLE `'.$child.'` (id int PRIMARY KEY, parent_id int NOT NULL, note varchar(100), CONSTRAINT `'.$foreign.'` FOREIGN KEY (parent_id) REFERENCES `'.$f->table.'` (id)) ENGINE=InnoDB');
    $f->pdo->exec("INSERT INTO `$child` VALUES (1, 1, 'retained child')");
    $before = $f->inspect();
    $f->pdo->exec('CREATE INDEX note_lookup ON `'.$child.'` (note)');
    $desired = $f->inspect();
    $f->pdo->exec('DROP INDEX note_lookup ON `'.$child.'`');
    $plan = $this->mysqlAlign->pin(mysqlDdlComparison($f, $desired, $before), ['all-supported']);
    $f->apply($plan);
    $f->store->record($plan['id'], ['status' => 'executing', 'operation_index' => 0]);
    $f->apply($plan, true);

    expect($f->pdo->query("SELECT note FROM `$child` FORCE INDEX (note_lookup) WHERE note = 'retained child'")->fetchColumn())->toBe('retained child')
        ->and($f->pdo->query("SELECT note FROM `$child` FORCE INDEX (`$foreign`) WHERE parent_id = 1")->fetchColumn())->toBe('retained child');
    $f->pdo->exec('SET SESSION transaction_read_only = OFF');
    expect(fn () => $f->pdo->exec("INSERT INTO `$child` VALUES (2, 999, 'orphan')"))->toThrow(PDOException::class);
});
