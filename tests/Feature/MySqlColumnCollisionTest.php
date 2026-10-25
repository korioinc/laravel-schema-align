<?php

declare(strict_types=1);

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

it('excludes case-colliding MySQL columns from plans while applying unrelated safe additions', function (string $existingName, string $desiredName): void {
    $f = $this->mysqlInstalledFixture;
    $table = '`'.MySqlInstalledFixture::TABLE.'`';
    $column = '`'.$existingName.'`';
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' varchar(255) NULL');
    $f->pdo->exec('UPDATE '.$table.' SET '.$column." = 'sample-a@example.test' WHERE id = 1");
    $migration = $f->directory.'/database/migrations/2026_01_01_000000_create_sample_items.php';
    file_put_contents($migration, str_replace(
        "\$table->string('note')->nullable();",
        "\$table->string('note')->nullable();\n            \$table->string(".var_export($desiredName, true).')->nullable();',
        file_get_contents($migration),
    ));
    $baseline = $f->fingerprint();
    $rows = $f->pdo->query('SELECT id, name, '.$column.' FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $ledger = $f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    $comparison = $f->align->diff();
    $operations = array_column($comparison['differences'], null, 'name');

    expect($comparison['errors'])->toBe([])
        ->and($operations[$desiredName]['automatic'])->toBeFalse()
        ->and($operations[$desiredName]['sql'])->toBe([])
        ->and(fn () => $f->align->pin($comparison, [$operations[$desiredName]['id']]))->toThrow(RuntimeException::class)
        ->and($f->fingerprint())->toBe($baseline);

    $plan = $f->align->pin($comparison, ['all-supported']);
    expect($f->fingerprint())->toBe($baseline);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->align->apply($plan['id'], $plan['signature']);

    expect($f->pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN))->toContain($existingName, 'note')->not->toContain($desiredName)
        ->and(array_column($f->pdo->query('SHOW INDEX FROM '.$table)->fetchAll(PDO::FETCH_ASSOC), 'Key_name'))->toContain('sa_mysql_installed_name_index')
        ->and($f->pdo->query('SELECT id, name, '.$column.' FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows)
        ->and($f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
})->with(['ASCII case' => ['email', 'Email'], 'Unicode case' => ['Ä', 'ä'], 'Unicode simple case' => ['İ', 'i']]);

it('pins and applies distinct MySQL sharp S identifiers without changing existing rows', function (): void {
    $f = $this->mysqlInstalledFixture;
    $table = '`'.MySqlInstalledFixture::TABLE.'`';
    $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN `ß` varchar(255) NULL');
    $f->pdo->exec('UPDATE '.$table." SET `ß` = 'preserved value' WHERE id = 1");
    $migration = $f->directory.'/database/migrations/2026_01_01_000000_create_sample_items.php';
    file_put_contents($migration, str_replace(
        "\$table->string('note')->nullable();",
        "\$table->string('note')->nullable();\n            \$table->string('ß')->nullable();\n            \$table->string('ẞ')->nullable();",
        file_get_contents($migration),
    ));
    $rows = $f->pdo->query('SELECT id, name, `ß` FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $ledger = $f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $comparison = $f->align->diff();
    expect($comparison['errors'])->toBe([]);
    $operations = array_column($comparison['differences'], null, 'name');
    expect($operations['ẞ']['automatic'])->toBeTrue();
    $plan = $f->align->pin($comparison, ['all-supported']);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->align->apply($plan['id'], $plan['signature']);

    expect($f->pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN))->toContain('ß', 'ẞ', 'note')
        ->and($f->pdo->query('SELECT id, name, `ß` FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows)
        ->and($f->pdo->query('SELECT migration, batch FROM `'.MySqlInstalledFixture::LEDGER.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger)
        ->and($f->align->diff()['differences'])->toBe([]);
});
