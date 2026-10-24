<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

it('preserves persistent PostgreSQL consumer reads and write permissions across diff', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with the disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);
    $consumer = null;

    try {
        $fixture->app->make('config')->set('database.connections.installed_target.options', [PDO::ATTR_PERSISTENT => true]);
        $consumer = $fixture->app->make('db')->connection()->getPdo();
        $read = fn (): mixed => $consumer->query('SELECT name FROM sample_records WHERE id = 1')->fetchColumn();
        $update = fn (): int|false => $consumer->exec("UPDATE sample_records SET name = 'Updated after diff' WHERE id = 1");
        expect($read())->toBe('Sample record A');

        $fixture->align->diff();
        $update();
        expect($read())->toBe('Updated after diff')
            ->and($fixture->recordName())->toBe('Updated after diff');

        $consumer->exec('SET default_transaction_read_only = on');
        $fixture->align->diff();
        expect($read())->toBe('Updated after diff');
        expect($update)->toThrow(PDOException::class);
    } finally {
        if ($consumer instanceof PDO) {
            $consumer->exec('SET default_transaction_read_only = off');
            $consumer->exec('SET search_path TO public');
        }
        $fixture->app->make('db')->purge('installed_target');
        $fixture->close();
    }
});

it('preserves persistent MySQL consumer reads and write permissions across diff', function (): void {
    if (getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_APP_TESTS=1 with a disposable loopback MySQL target.');
    }
    $fixture = new MySqlInstalledFixture($this->app);
    $consumer = null;

    try {
        $fixture->app->make('config')->set('database.connections.consumer_mysql.options', [PDO::ATTR_PERSISTENT => true]);
        $consumer = $fixture->app->make('db')->connection()->getPdo();
        $read = fn (): mixed => $consumer->query('SELECT name FROM `'.MySqlInstalledFixture::TABLE.'` WHERE id = 1')->fetchColumn();
        $update = fn (): int|false => $consumer->exec('UPDATE `'.MySqlInstalledFixture::TABLE."` SET name = 'Updated after diff' WHERE id = 1");
        expect($read())->toBe('Sample item A');

        $fixture->align->diff();
        $update();
        expect($read())->toBe('Updated after diff')
            ->and($fixture->itemName())->toBe('Updated after diff');

        $consumer->exec('SET SESSION transaction_read_only = ON');
        $fixture->align->diff();
        expect($read())->toBe('Updated after diff');
        expect($update)->toThrow(PDOException::class);
    } finally {
        if ($consumer instanceof PDO) {
            $consumer->exec('SET SESSION transaction_read_only = OFF');
        }
        $fixture->app->make('db')->purge('consumer_mysql');
        $fixture->close();
    }
});
