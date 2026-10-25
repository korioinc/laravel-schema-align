<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\MySqlComparator;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;
use Korioinc\SchemaAlign\Tests\Support\MySqlInstalledFixture;

it('applies indexes with only their actual column prerequisites and preserves data', function (string $engine, bool $newKey, string $selection): void {
    $mysql = $engine === 'mysql';

    if ($mysql ? getenv('SCHEMA_ALIGN_MYSQL_APP_TESTS') !== '1' : ! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Enable the disposable '.$engine.' application fixture to test index dependencies.');
    }
    $fixture = $mysql ? new MySqlInstalledFixture($this->app) : new ApplyFixture($this->app);

    try {
        $app = $mysql ? $fixture->app : $this->app;
        $connection = $mysql ? 'consumer_mysql' : 'schema_align_testing';
        $app->make('config')->set('schema-align.target_instance', 'isolated-tests');
        $table = $mysql ? '`'.MySqlInstalledFixture::TABLE.'`' : $fixture->quotedSchema.'.sample_items';
        $tableKey = $mysql ? '@database.'.MySqlInstalledFixture::TABLE : $fixture->schema.'.sample_items';
        $catalog = $mysql ? new MySqlCatalog($fixture->pdo, MySqlInstalledFixture::LEDGER) : new PostgresCatalog($fixture->pdo, [$fixture->schema]);
        $fixture->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN email varchar(100)');
        $fixture->pdo->exec("UPDATE $table SET email = 'sample-a@example.test' WHERE id = 1");
        $rows = $fixture->pdo->query('SELECT id, name, email FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $ledger = $mysql ? $fixture->pdo->query('SELECT * FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
        $before = $catalog->inspect();

        if (! $mysql) {
            $fixture->pdo->beginTransaction();
        }

        if ($newKey) {
            $fixture->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN note varchar(100)');
        }
        $fixture->pdo->exec('CREATE INDEX sa_dependency_lookup ON '.$table.' ('.($newKey ? 'note' : 'email').')');
        // Capture the expected result from real DDL, independently of plan projection.
        $expected = $catalog->inspect();
        $fixture->pdo->exec("ALTER TABLE $table ADD COLUMN required_name varchar(100) NOT NULL DEFAULT 'initial'");
        $fixture->pdo->exec('ALTER TABLE '.$table.' ALTER COLUMN required_name DROP DEFAULT');
        $desired = $catalog->inspect();

        if ($mysql) {
            $fixture->pdo->exec('DROP INDEX sa_dependency_lookup ON '.$table);
            $fixture->pdo->exec('ALTER TABLE '.$table.' DROP COLUMN required_name');

            if ($newKey) {
                $fixture->pdo->exec('ALTER TABLE '.$table.' DROP COLUMN note');
            }
        } else {
            $fixture->pdo->rollBack();
        }
        expect($catalog->inspect()->fingerprint())->toBe($before->fingerprint());
        $comparator = $mysql ? new MySqlComparator : new SchemaComparator;
        $comparison = [...$comparator->compare($desired, $before),
            'actual' => $before->toArray(), 'desired' => $desired->toArray(),
            'source' => ['revision' => 'index-dependencies-fixture'],
            'binding' => TargetConnection::binding($app->make('config')->get('database.connections.'.$connection), 'isolated-tests', $app->environment()),
        ];
        $operations = array_column($comparison['differences'], null, 'name');
        $index = $operations['sa_dependency_lookup'];
        expect($comparison['errors'])->toBe([])
            ->and($operations['required_name']['automatic'])->toBeFalse()
            ->and($index['automatic'])->toBeTrue()
            ->and($index['dependencies'])->toBe($newKey ? [$operations['note']['id']] : []);

        if ($newKey) {
            expect(fn () => $fixture->align->pin($comparison, [$index['id']]))->toThrow(RuntimeException::class);
        }
        $plan = $fixture->align->pin($comparison, $selection === 'all-supported' ? ['all-supported'] : [$index['id']]);
        $fixture->align->apply($plan['id'], $plan['signature']);
        $fixture->align->apply($plan['id'], $plan['signature']);
        $applied = $catalog->inspect();

        expect($applied->fingerprint())->toBe($expected->fingerprint())
            ->and($applied->tables[$tableKey]['columns'])->not->toHaveKey('required_name')
            ->and($fixture->pdo->query('SELECT id, name, email FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);

        if ($mysql) {
            expect($fixture->pdo->query('SELECT * FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
        }
    } finally {
        $fixture->close();
    }
})->with(['pgsql', 'mysql'])->with([
    'existing key with all-supported' => [false, 'all-supported'],
    'existing key with explicit selection' => [false, 'index'],
    'new key with all-supported' => [true, 'all-supported'],
]);
