<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

it('diffs plans and applies unrelated changes when a MySQL default contains TABLESPACE', function (): void {
    if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_APP_TESTS=1 with a disposable loopback MySQL target.');
    }
    $fixture = new MySqlInstalledFixture($this->app);

    try {
        $fixture->pdo->exec('ALTER TABLE `'.MySqlInstalledFixture::TABLE."` ADD COLUMN literal_note varchar(255) NOT NULL DEFAULT 'TABLESPACE archive'");
        file_put_contents($fixture->directory.'/database/migrations/2026_01_02_000000_literal_note.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::table('sa_mysql_installed_sample_items', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('literal_note')->default('TABLESPACE archive');
        });
    }
};
MIGRATION);
        $before = $fixture->fingerprint();
        $fixture->align->diff();
        $plan = $fixture->align->plan(['all-safe']);
        expect($fixture->fingerprint())->toBe($before);
        $fixture->align->apply($plan['id'], $plan['signature']);
        expect($fixture->align->diff()['differences'])->toBe([])
            ->and($fixture->itemName())->toBe('Sample item A')
            ->and($fixture->pdo->query('SELECT literal_note FROM `'.MySqlInstalledFixture::TABLE.'` WHERE id=1')->fetchColumn())->toBe('TABLESPACE archive');
    } finally {
        $fixture->close();
    }
});

it('refuses replay redirected to the original MySQL database before creating a ledger or running migrations', function (string $operation): void {
    if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_APP_TESTS=1 with a disposable loopback MySQL target.');
    }
    $fixture = new MySqlInstalledFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $connection = $config->get('database.connections.consumer_mysql');
        $connection['use_db_after_connecting'] = false;
        $connection['options'][PDO::MYSQL_ATTR_INIT_COMMAND] = 'USE `'.$connection['database'].'`';
        $config->set('database.connections.consumer_mysql', $connection);
        $fixture->pdo->exec('DROP TABLE `'.MySqlInstalledFixture::LEDGER.'`');
        $migration = $fixture->directory.'/database/migrations/2025_12_31_000000_change_sample_items.php';
        file_put_contents($migration, <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('sa_mysql_installed_sample_items', function (Blueprint $table): void {
            $table->string('replay_damage')->nullable();
        });
        DB::table('sa_mysql_installed_sample_items')->update(['name' => 'Changed by replay']);
    }
};
MIGRATION);
        $before = $fixture->fingerprint();

        expect(fn () => $operation === 'diff' ? $fixture->align->diff() : $fixture->align->plan(['all-safe']))
            ->toThrow(RuntimeException::class);
        $ledger = $fixture->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $ledger->execute([MySqlInstalledFixture::LEDGER]);
        expect((int) $ledger->fetchColumn())->toBe(0)
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->itemName())->toBe('Sample item A');

        unset($connection['options'][PDO::MYSQL_ATTR_INIT_COMMAND]);
        $config->set('database.connections.consumer_mysql', $connection);
        unlink($migration);
        $operation === 'diff' ? $fixture->align->diff() : $fixture->align->plan(['all-safe']);
        $ledger->execute([MySqlInstalledFixture::LEDGER]);
        expect((int) $ledger->fetchColumn())->toBe(0)
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->itemName())->toBe('Sample item A');
    } finally {
        $fixture->close();
    }
})->with(['diff', 'plan']);

it('preserves other databases when replay SQL uses single or multiple DML targets', function (string $operation): void {
    if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1' || getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable MySQL administrator tests are required.');
    }
    $fixture = new MySqlInstalledFixture($this->app);
    $outside = '`sa_replay_dml_'.bin2hex(random_bytes(8)).'`';
    $admin = new PDO(getenv('SCHEMA_ALIGN_MYSQL_DSN'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    try {
        $config = $fixture->app->make('config');
        $config->set('database.connections.consumer_mysql.username', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'));
        $config->set('database.connections.consumer_mysql.password', getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'));
        $admin->exec('CREATE DATABASE '.$outside);
        $admin->exec('CREATE TABLE '.$outside.'.records (id integer PRIMARY KEY, note text)');
        $admin->exec("INSERT INTO {$outside}.records VALUES (1, 'Original row')");
        $local = '`'.MySqlInstalledFixture::TABLE.'`';
        $sql = match ($operation) {
            'update' => "UPDATE {$outside}.records SET note = 'Changed by replay'",
            'insert' => "INSERT INTO {$outside}.records VALUES (2, 'Inserted by replay')",
            'delete' => "DELETE FROM {$outside}.records",
            'replace' => "REPLACE INTO {$outside}.records VALUES (1, 'Replaced by replay')",
            'cte' => "WITH source AS (SELECT 1 AS id) UPDATE {$outside}.records SET note = 'Changed by CTE' WHERE id IN (SELECT id FROM source)",
            'joined update' => "UPDATE {$local} l RIGHT JOIN {$outside}.records o ON l.id = o.id SET o.note = 'Changed by join'",
            'multi delete' => "DELETE FROM o USING {$outside}.records o LEFT JOIN {$local} l ON l.id = o.id",
        };
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_external_write.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
        $before = $fixture->fingerprint();
        $failure = null;

        try {
            $fixture->align->plan(['all-safe']);
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        expect($admin->query('SELECT id, note FROM '.$outside.'.records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))
            ->toBe([['id' => 1, 'note' => 'Original row']])
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->itemName())->toBe('Sample item A')
            ->and($failure?->getMessage())->toContain('replay DML');
    } finally {
        $admin->exec('DROP DATABASE IF EXISTS '.$outside);
        $fixture->close();
    }
})->with(['update', 'insert', 'delete', 'replace', 'cte', 'joined update', 'multi delete']);

it('cannot apply an incomplete MySQL baseline and can retry after correcting its source', function (): void {
    if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_APP_TESTS=1 with a disposable loopback MySQL target.');
    }
    $fixture = new MySqlInstalledFixture($this->app);

    try {
        $before = $fixture->fingerprint();
        file_put_contents($fixture->directory.'/database/migrations/2026_01_05_000000_fail.php', <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('partial_replay', function ($table): void { $table->bigInteger('id'); });
        throw new RuntimeException('Deliberately incomplete baseline');
    }
};
MIGRATION);
        expect(fn () => $fixture->align->plan(['all-safe']))->toThrow(RuntimeException::class)
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->itemName())->toBe('Sample item A');
        unlink($fixture->directory.'/database/migrations/2026_01_05_000000_fail.php');
        $plan = $fixture->align->plan(['all-safe']);
        $fixture->align->apply($plan['id'], $plan['signature']);
        expect($fixture->align->diff()['differences'])->toBe([])
            ->and($fixture->itemName())->toBe('Sample item A');
    } finally {
        $fixture->close();
    }
});
