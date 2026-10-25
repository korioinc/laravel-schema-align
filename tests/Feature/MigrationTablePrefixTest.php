<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

it('keeps the prefixed migration ledger outside diff and apply while retaining its history', function (string $driver, bool $custom): void {
    $mysql = $driver === 'mysql';

    if (getenv($mysql ? 'SCHEMA_ALIGN_MYSQL_APP_TESTS' : 'SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Enable installed application tests with the disposable database target.');
    }
    $fixture = $mysql ? new MySqlInstalledFixture($this->app) : new InstalledAppFixture($this->app);
    $connection = $mysql ? 'consumer_mysql' : 'installed_target';
    $table = $mysql ? MySqlInstalledFixture::TABLE : 'sample_records';
    $oldLedger = $mysql ? MySqlInstalledFixture::LEDGER : 'migrations';
    $logicalLedger = $custom ? 'schema_history' : $oldLedger;
    $physicalLedger = 'p_'.$logicalLedger;
    $qualifier = $mysql ? '' : $fixture->quotedSchema.'.';
    $quote = static fn (string $name): string => $mysql ? '`'.$name.'`' : '"'.$name.'"';
    $renamed = false;

    try {
        if ($mysql) {
            $fixture->pdo->exec('RENAME TABLE '.$quote($table).' TO '.$quote('p_'.$table).', '.$quote($oldLedger).' TO '.$quote($physicalLedger));
        } else {
            $fixture->pdo->exec('ALTER TABLE '.$qualifier.$quote($table).' RENAME TO '.$quote('p_'.$table));
            $fixture->pdo->exec('ALTER TABLE '.$qualifier.$quote($oldLedger).' RENAME TO '.$quote($physicalLedger));
            $fixture->pdo->exec('ALTER INDEX '.$qualifier.$quote('migrations_pkey').' RENAME TO ledger_key_to_preserve');
        }
        $renamed = true;
        $config = $fixture->app->make('config');
        $config->set('database.connections.'.$connection.'.prefix', 'p_');
        $config->set('schema-align.engines.'.$driver.'.migration_table', $logicalLedger);
        $fixture->pdo->exec('INSERT INTO '.$qualifier.$quote($physicalLedger)." (migration, batch) VALUES ('retired_migration', 2)");
        $catalog = $mysql ? new MySqlCatalog($fixture->pdo, $physicalLedger) : new PostgresCatalog($fixture->pdo, [$fixture->schema], $physicalLedger);
        $before = $catalog->inspect()->fingerprint();
        $history = $fixture->pdo->query('SELECT id, migration, batch FROM '.$qualifier.$quote($physicalLedger).' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $comparison = $fixture->align->diff();
        $schema = $mysql ? '@database' : $fixture->schema;

        expect($comparison['errors'])->toBe([])
            ->and($comparison['actual']['tables'])->not->toHaveKey($schema.'.'.$physicalLedger)
            ->and($comparison['desired']['tables'])->not->toHaveKey($schema.'.'.$physicalLedger)
            ->and($comparison['actual']['migrationNames'])->toContain('retired_migration')
            ->and($comparison['desired']['migrationNames'])->not->toBeEmpty()
            ->and($comparison['historical_missing_files'])->toContain('retired_migration')
            ->and($catalog->inspect()->fingerprint())->toBe($before);
        $plan = $fixture->align->pin($comparison, ['all-supported']);
        expect($plan['migration_table'])->toBe($physicalLedger)
            ->and(array_column($plan['operations'], 'table'))->not->toContain($schema.'.'.$physicalLedger);
        $fixture->align->reviewPlan($plan['id']);
        $fixture->align->apply($plan['id'], $plan['signature']);
        $fixture->align->apply($plan['id'], $plan['signature']);

        expect($fixture->pdo->query('SELECT id, migration, batch FROM '.$qualifier.$quote($physicalLedger).' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($history)
            ->and($fixture->pdo->query('SELECT name FROM '.$qualifier.$quote('p_'.$table).' WHERE id = 1')->fetchColumn())->toBe($mysql ? 'Sample item A' : 'Sample record A')
            ->and($fixture->align->diff()['differences'])->toBe([]);
        $config->set('database.connections.'.$connection.'.prefix', 'q_');
        expect(fn () => $fixture->align->reviewPlan($plan['id']))->toThrow(RuntimeException::class, 'another target');
    } finally {
        if ($mysql && $renamed) {
            $fixture->pdo->exec('RENAME TABLE '.$quote('p_'.$table).' TO '.$quote($table).', '.$quote($physicalLedger).' TO '.$quote($oldLedger));
        }
        $fixture->close();
    }
})->with([['pgsql', false], ['pgsql', true], ['mysql', false], ['mysql', true]]);
