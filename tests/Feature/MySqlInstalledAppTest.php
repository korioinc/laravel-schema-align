<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Replay\ReplayNamespace;
use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

beforeEach(function (): void {
    if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_APP_TESTS=1 with a disposable loopback MySQL target.');
    }
    $this->mysqlInstalledFixture = new MySqlInstalledFixture($this->app);
});

afterEach(function (): void {
    if (isset($this->mysqlInstalledFixture)) {
        $this->mysqlInstalledFixture->close();
    }
});

it('supports MySQL primary key policies through installed application replay and cleanup', function (bool $generate, bool $require): void {
    if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1'
        || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER') || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD')) {
        $this->markTestSkipped('Explicit disposable administrator credentials are required for session primary key policies.');
    }
    $f = $this->mysqlInstalledFixture;

    if ($generate && $f->pdo->query("SHOW SESSION VARIABLES WHERE Variable_name = 'sql_generate_invisible_primary_key'")->fetchAll() === []) {
        $this->markTestSkipped('Generated invisible primary keys require MySQL 8.0.30 or later.');
    }
    $config = $f->app->make('config');
    $config->set('database.connections.consumer_mysql.username', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'));
    $config->set('database.connections.consumer_mysql.password', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'));
    $settings = 'SET SESSION sql_require_primary_key = '.($require ? 'ON' : 'OFF');

    if ($generate) {
        $settings .= ', sql_generate_invisible_primary_key = ON';
    }
    $config->set('database.connections.consumer_mysql.options', [PDO::MYSQL_ATTR_INIT_COMMAND => $settings]);
    $names = static fn (): array => $f->pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'align-%' ORDER BY SCHEMA_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $namesBefore = $names();

    try {
        $before = $f->fingerprint();
        $plan = $f->align->plan(['all-safe']);

        expect($f->fingerprint())->toBe($before)->and($names())->toBe($namesBefore);
        expect($f->align->apply($plan['id'], $plan['signature'])['status'])->toBe('applied')
            ->and($f->itemName())->toBe('Sample item A')
            ->and($names())->toBe($namesBefore);
    } finally {
        // The fixture holds the shared lock for this disposable MySQL server.
        foreach (array_diff($names(), $namesBefore) as $name) {
            $f->pdo->exec('DROP DATABASE `'.str_replace('`', '``', $name).'`');
        }
    }
})->with([
    'generated invisible primary keys' => [true, false],
    'required primary keys' => [false, true],
    'both primary key policies' => [true, true],
]);

it('refuses a saved Unicode MySQL plan after the configured connection charset changes', function (): void {
    $f = $this->mysqlInstalledFixture;
    $migration = $f->directory.'/database/migrations/2026_01_01_000000_create_sample_items.php';
    file_put_contents($migration, str_replace("'note'", "'café'", file_get_contents($migration)));
    $plan = $f->align->plan(['all-safe']);
    $before = $f->fingerprint();
    $config = $f->app->make('config');
    $config->set('database.connections.consumer_mysql.charset', 'latin1');
    $config->set('database.connections.consumer_mysql.collation', 'latin1_swedish_ci');

    expect(fn () => $f->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class, 'Stale or divergent MySQL plan')
        ->and($f->fingerprint())->toBe($before)
        ->and($f->itemName())->toBe('Sample item A');

    $config->set('database.connections.consumer_mysql.charset', 'utf8mb4');
    $config->set('database.connections.consumer_mysql.collation', 'utf8mb4_0900_ai_ci');
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec('UPDATE `'.MySqlInstalledFixture::TABLE."` SET `café` = 'Preserved encoding' WHERE id = 1");
    expect($f->pdo->query('SELECT `café` FROM `'.MySqlInstalledFixture::TABLE.'` WHERE id = 1')->fetchColumn())->toBe('Preserved encoding')
        ->and($f->itemName())->toBe('Sample item A');
});

it('preserves MySQL global and persisted settings during administrator replay', function (string $sql): void {
    if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1'
        || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER') || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD')) {
        $this->markTestSkipped('Explicit disposable administrator credentials are required.');
    }
    $f = $this->mysqlInstalledFixture;
    $admin = new PDO(getenv('SCHEMA_ALIGN_MYSQL_DSN'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $global = fn (): mixed => $admin->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
    $persisted = fn (): mixed => $admin->query("SELECT VARIABLE_VALUE FROM performance_schema.persisted_variables WHERE VARIABLE_NAME='sql_mode'")->fetchColumn();
    $before = $global();
    $stored = $persisted();
    $config = $f->app->make('config');
    $config->set('database.connections.consumer_mysql.username', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'));
    $config->set('database.connections.consumer_mysql.password', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'));
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_global_setting.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');

    try {
        $error = null;

        try {
            $f->align->diff();
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        expect($global())->toBe($before)->and($persisted())->toBe($stored)
            ->and($error)->toBeInstanceOf(RuntimeException::class);
    } finally {
        $admin->exec('SET GLOBAL sql_mode='.$admin->quote($before));
        $admin->exec($stored === false ? 'RESET PERSIST IF EXISTS sql_mode' : 'SET PERSIST_ONLY sql_mode='.$admin->quote($stored));
    }
})->with([
    'global system variable' => "SET @@GLOBAL.sql_mode = ''",
    'persisted variable' => "SET PERSIST sql_mode = ''",
    'persisted only' => "SET PERSIST_ONLY sql_mode = ''",
    'qualified persistent' => "SET @@PERSIST.sql_mode = ''",
    'qualified persistent only' => "SET @@PERSIST_ONLY.sql_mode = ''",
    'mixed scope assignment' => "SET SESSION sql_mode = @@SESSION.sql_mode, @@GLOBAL.sql_mode = ''",
]);

it('replays the consumer MySQL baseline afresh and preserves data through repeated approval', function (bool $administrator): void {
    $f = $this->mysqlInstalledFixture;

    if ($administrator) {
        if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1'
            || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER') || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD')) {
            $this->markTestSkipped('Explicit disposable administrator credentials are required for installed authorization proof.');
        }
        $config = $f->app->make('config');
        $config->set('database.connections.consumer_mysql.username', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'));
        $config->set('database.connections.consumer_mysql.password', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'));
    }
    $before = $f->fingerprint();
    $ledger = $f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $comparison = $f->align->diff();
    $plan = $f->align->pin($comparison, ['all-safe']);
    expect($f->fingerprint())->toBe($before);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec('UPDATE `'.MySqlInstalledFixture::TABLE."` SET note = 'retained after duplicate approval' WHERE id = 1");
    $f->align->apply($plan['id'], $plan['signature']);
    expect($f->pdo->query('SELECT note FROM `'.MySqlInstalledFixture::TABLE.'` WHERE id = 1')->fetchColumn())->toBe('retained after duplicate approval');
    $after = $f->fingerprint();
    $f->writeMigration(true);
    $edited = $f->align->diff();
    expect($f->fingerprint())->toBe($after);
    $editedPlan = $f->align->pin($edited, ['all-safe']);
    $f->align->apply($editedPlan['id'], $editedPlan['signature']);
    $f->pdo->exec('UPDATE `'.MySqlInstalledFixture::TABLE."` SET future_note = 'edited create replayed' WHERE id = 1");
    expect($f->pdo->query('SELECT future_note FROM `'.MySqlInstalledFixture::TABLE.'` WHERE id = 1')->fetchColumn())->toBe('edited create replayed')
        ->and($f->itemName())->toBe('Sample item A')
        ->and($f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
})->with(['configured account' => false, 'administrator account' => true]);

it('applies comparison-generated MySQL index renames while preserving indexed reads and uniqueness', function (bool $unique): void {
    $f = $this->mysqlInstalledFixture;
    $table = '`'.MySqlInstalledFixture::TABLE.'`';
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN note varchar(255) NULL');
    $f->pdo->exec('CREATE '.($unique ? 'UNIQUE ' : '').'INDEX legacy_name_lookup ON '.$table.' (name)');

    if ($unique) {
        $migration = $f->directory.'/database/migrations/2026_01_01_000000_create_sample_items.php';
        file_put_contents($migration, str_replace(
            "\$table->index('name', 'sa_mysql_installed_name_index');",
            "\$table->unique('name', 'sa_mysql_installed_name_index');",
            file_get_contents($migration),
        ));
    }
    $ledger = $f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $indexedRead = 'SELECT name FROM '.$table.' FORCE INDEX (`sa_mysql_installed_name_index`) WHERE name = \'Sample item A\'';
    expect(fn () => $f->pdo->query($indexedRead))->toThrow(PDOException::class);

    $comparison = $f->align->diff();
    $plan = $f->align->pin($comparison, ['all-safe']);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->align->apply($plan['id'], $plan['signature']);

    expect($f->pdo->query($indexedRead)->fetchColumn())->toBe('Sample item A')
        ->and($f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
    $duplicate = 'INSERT INTO '.$table." (name) VALUES ('Sample item A')";

    if ($unique) {
        expect(fn () => $f->pdo->exec($duplicate))->toThrow(PDOException::class);
    } else {
        $f->pdo->exec($duplicate);
        expect($f->pdo->query($indexedRead)->fetchAll(PDO::FETCH_COLUMN))->toBe(['Sample item A', 'Sample item A']);
    }
})->with(['ordinary index' => false, 'unique index' => true]);

it('refuses original MySQL writes after arithmetic that resembles a comment', function (): void {
    $f = $this->mysqlInstalledFixture;
    $database = $f->app->make('config')->get('database.connections.consumer_mysql.database');
    $sql = 'WITH ids AS (SELECT 1--1 AS id) UPDATE `'.str_replace('`', '``', $database).'`.`'.MySqlInstalledFixture::TABLE.'` SET name=\'Unauthorized update\' WHERE id IN (SELECT id FROM ids)';
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_original_scope.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
    $before = $f->fingerprint();

    expect(fn () => $f->align->diff())->toThrow(RuntimeException::class)
        ->and($f->fingerprint())->toBe($before)
        ->and($f->itemName())->toBe('Sample item A');
});

it('refuses dropping the original MySQL database during replay and preserves its rows', function (): void {
    $f = $this->mysqlInstalledFixture;
    $database = $f->app->make('config')->get('database.connections.consumer_mysql.database');
    $sql = 'DROP DATABASE `'.str_replace('`', '``', $database).'`';
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_drop_original.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
    $before = $f->fingerprint();

    expect(fn () => $f->align->diff())->toThrow(RuntimeException::class, 'global scope')
        ->and($f->fingerprint())->toBe($before)
        ->and($f->itemName())->toBe('Sample item A');
});

it('protects original MySQL databases with digit or dollar-prefixed identifiers', function (string $prefix): void {
    if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1'
        || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER') || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD')) {
        $this->markTestSkipped('Explicit disposable administrator credentials are required.');
    }
    $f = $this->mysqlInstalledFixture;
    $database = $prefix.bin2hex(random_bytes(6));
    $quoted = '`'.$database.'`';
    $admin = new PDO(getenv('SCHEMA_ALIGN_MYSQL_DSN'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');

    try {
        $admin->exec('CREATE TABLE '.$quoted.'.`'.MySqlInstalledFixture::TABLE.'` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(255) NOT NULL) ENGINE=InnoDB');
        $admin->exec('INSERT INTO '.$quoted.'.`'.MySqlInstalledFixture::TABLE."` (name) VALUES ('Original sample item')");
        $config = $f->app->make('config');
        $config->set('database.connections.consumer_mysql.database', $database);
        $config->set('database.connections.consumer_mysql.username', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'));
        $config->set('database.connections.consumer_mysql.password', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'));
        $sql = 'UPDATE '.$database.'.'.MySqlInstalledFixture::TABLE." SET name='Unauthorized update' WHERE id=1";
        file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_original_scope.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');

        expect(fn () => $f->align->diff())->toThrow(RuntimeException::class)
            ->and($admin->query('SELECT name FROM '.$quoted.'.`'.MySqlInstalledFixture::TABLE.'` WHERE id=1')->fetchColumn())->toBe('Original sample item');
    } finally {
        $admin->exec('DROP DATABASE '.$quoted);
    }
})->with(['leading digit' => '123sa_original_', 'leading dollar' => '$sa_original_']);

it('reconciles a completed MySQL apply journal without rerunning committed DDL', function (): void {
    $f = $this->mysqlInstalledFixture;
    $plan = $f->align->plan(['all-safe']);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec('UPDATE `'.MySqlInstalledFixture::TABLE."` SET note='Committed before journal loss' WHERE id=1");
    $f->align->store()->record($plan['id'], ['status' => 'partial']);
    $f->align->finishApply($plan['id'], $plan['signature']);

    expect($f->align->store()->status($plan['id'])['status'])->toBe('applied')
        ->and($f->pdo->query('SELECT note FROM `'.MySqlInstalledFixture::TABLE.'` WHERE id=1')->fetchColumn())->toBe('Committed before journal loss')
        ->and($f->itemName())->toBe('Sample item A');
});

it('removes abandoned replay databases after successful apply while preserving another binding and original rows', function (): void {
    $f = $this->mysqlInstalledFixture;
    $comparison = $f->align->diff();
    $plan = $f->align->pin($comparison, ['all-safe']);
    $ledger = $f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $manager = new ReplayNamespace($f->pdo, $comparison['binding'], $comparison['actual']['identity'], ['@database']);
    $other = new ReplayNamespace($f->pdo, array_replace($comparison['binding'], ['instance' => 'another-installed-app']), $comparison['actual']['identity'], ['@database']);
    $owned = $manager->create();
    $unrelated = $other->create();
    $ownedTable = '`'.$owned['namespaces'][0].'`.sentinel';
    $otherTable = '`'.$unrelated['namespaces'][0].'`.sentinel';

    try {
        foreach ([$ownedTable, $otherTable] as $table) {
            $f->pdo->exec('CREATE TABLE '.$table.' (value integer)');
            $f->pdo->exec('INSERT INTO '.$table.' VALUES (37)');
        }
        $manager->release();
        $other->release();
        $f->align->apply($plan['id'], $plan['signature']);

        expect(fn () => $f->pdo->query('SELECT value FROM '.$ownedTable))->toThrow(PDOException::class);
        expect((int) $f->pdo->query('SELECT value FROM '.$otherTable)->fetchColumn())->toBe(37)
            ->and($f->itemName())->toBe('Sample item A')
            ->and($f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
        $f->pdo->exec('UPDATE `'.MySqlInstalledFixture::TABLE."` SET note = 'applied-and-retained' WHERE id = 1");
        expect($f->pdo->query('SELECT note FROM `'.MySqlInstalledFixture::TABLE.'` WHERE id = 1')->fetchColumn())->toBe('applied-and-retained');
    } finally {
        $manager->cleanup($owned['run_id']);
        $other->cleanup($unrelated['run_id']);
        $manager->release();
        $other->release();
    }
});
