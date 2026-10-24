<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Planning\Operation;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\MySqlComparator;

beforeEach(function (): void {
    $dsn = getenv('SCHEMA_ALIGN_MYSQL_DSN');

    if (! $dsn) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_DSN for real MySQL identifier verification.');
    }

    if (! preg_match('/\Amysql:host=(?:127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=schema-align-tests(?:-[a-z0-9]+)?(?:;charset=utf8mb4)?\z/D', $dsn, $match)
        || (int) $match[1] < 1 || (int) $match[1] > 65535 || getenv('SCHEMA_ALIGN_MYSQL_USER') !== 'schema_align_test') {
        throw new RuntimeException('MySQL identifier tests require a disposable loopback database and schema_align_test user.');
    }
    $this->identifierLock = fopen(sys_get_temp_dir().'/schema-align-mysql-'.hash('sha256', $dsn).'.lock', 'c');

    if ($this->identifierLock === false || ! flock($this->identifierLock, LOCK_EX)) {
        throw new RuntimeException('Cannot serialize MySQL identifier fixtures.');
    }
    $this->identifierPdo = new PDO($dsn, getenv('SCHEMA_ALIGN_MYSQL_USER'), getenv('SCHEMA_ALIGN_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $this->identifierTable = 'sa_identifier_'.bin2hex(random_bytes(5));
});

afterEach(function (): void {
    if (isset($this->identifierPdo)) {
        $this->identifierPdo->exec('DROP TABLE IF EXISTS `'.$this->identifierTable.'`');
        $this->identifierPdo->exec('DROP TABLE IF EXISTS `'.strtoupper($this->identifierTable).'`');
    }

    if (isset($this->identifierLock) && is_resource($this->identifierLock)) {
        flock($this->identifierLock, LOCK_UN);
        fclose($this->identifierLock);
    }
});

it('matches real MySQL column and index equality and projects permitted additions', function (string $collection, string $existingName, string $desiredName, bool $collides): void {
    $pdo = $this->identifierPdo;
    $table = '`'.$this->identifierTable.'`';
    $pdo->exec('CREATE TABLE '.$table.' (`id` int NULL, `other` int NULL) ENGINE=InnoDB');
    $existing = $collection === 'columns' ? 'COLUMN `'.$existingName.'` int NULL' : 'INDEX `'.$existingName.'` (`id`)';
    $wanted = $collection === 'columns' ? 'COLUMN `'.$desiredName.'` int NULL' : 'INDEX `'.$desiredName.'` (`other`)';
    $pdo->exec('ALTER TABLE '.$table.' ADD '.$existing);
    $catalog = new MySqlCatalog($pdo);
    $before = $catalog->inspect();

    if ($collides) {
        try {
            $pdo->exec('ALTER TABLE '.$table.' ADD '.$wanted);

            throw new LogicException('MySQL unexpectedly accepted colliding identifiers.');
        } catch (PDOException $error) {
            expect($error->errorInfo[1])->toBe($collection === 'columns' ? 1060 : 1061);
        }
        $pdo->exec('ALTER TABLE '.$table.' DROP '.($collection === 'columns' ? 'COLUMN' : 'INDEX').' `'.$existingName.'`');
    }
    $pdo->exec('ALTER TABLE '.$table.' ADD '.$wanted);
    $after = $catalog->inspect();
    $comparator = new MySqlComparator;
    $comparison = $comparator->compare($after, $before);
    expect($comparison['errors'])->toBe([]);
    $operations = array_column($comparison['differences'], null, 'name');
    $operation = $operations[$desiredName];

    expect($comparison['errors'])->toBe([])
        ->and($operation['automatic'])->toBe(! $collides)
        ->and($operation['sql'] !== [])->toBe(! $collides)
        ->and($comparator->compare($after, $after)['errors'])->toBe([])
        ->and($comparator->compare($after, $after)['differences'])->toBe([]);

    if (! $collides) {
        $projected = Operation::project($before, $operation);
        expect($projected->fingerprint())->toBe($after->fingerprint());
        $pdo->exec('ALTER TABLE '.$table.' DROP '.($collection === 'columns' ? 'COLUMN' : 'INDEX').' `'.$desiredName.'`');
        foreach ($operation['sql'] as $sql) {
            $pdo->exec($sql);
        }
        expect($catalog->inspect()->fingerprint())->toBe($projected->fingerprint());
    }
})->with(['columns', 'indexes'])->with([
    'ASCII case' => ['email', 'Email', true],
    'accented case' => ['Ä', 'ä', true],
    'dotted I' => ['İ', 'i', true],
    'sharp S versions' => ['ß', 'ẞ', false],
    'no full fold' => ['ß', 'ss', false],
    'accent significance' => ['é', 'e', false],
    'final sigma significance' => ['Σ', 'ς', false],
    'Kelvin identifier case' => ['K', 'k', true],
    'long S significance' => ['ſ', 's', false],
]);

it('preserves semantic index renames with server identifier keys in signed projections', function (): void {
    $pdo = $this->identifierPdo;
    $table = '`'.$this->identifierTable.'`';
    $pdo->exec('CREATE TABLE '.$table.' (`id` int NULL, INDEX `ß` (`id`)) ENGINE=InnoDB');
    $catalog = new MySqlCatalog($pdo);
    $before = $catalog->inspect();
    $pdo->exec('ALTER TABLE '.$table.' RENAME INDEX `ß` TO `ẞ`');
    $after = $catalog->inspect();
    $comparison = (new MySqlComparator)->compare($after, $before);

    expect($comparison['errors'])->toBe([])->and($comparison['differences'])->toHaveCount(1);
    $operation = $comparison['differences'][0];
    expect($operation['kind'])->toBe('rename_index')
        ->and($operation['automatic'])->toBeTrue()
        ->and(Operation::project($before, $operation)->fingerprint())->toBe($after->fingerprint());
    $pdo->exec('ALTER TABLE '.$table.' RENAME INDEX `ẞ` TO `ß`');
    $pdo->exec($operation['sql'][0]);
    expect($catalog->inspect()->fingerprint())->toBe($after->fingerprint());
});

it('honors real MySQL table name sensitivity without inferring table renames', function (): void {
    $pdo = $this->identifierPdo;
    $lower = $this->identifierTable;
    $upper = strtoupper($lower);
    $pdo->exec('CREATE TABLE `'.$lower.'` (`id` int NULL) ENGINE=InnoDB');
    $catalog = new MySqlCatalog($pdo);
    $before = $catalog->inspect();
    $setting = $before->platform['settings']['lower_case_table_names'];

    if ($setting !== 0) {
        try {
            $pdo->exec('CREATE TABLE `'.$upper.'` (`id` int NULL) ENGINE=InnoDB');

            throw new LogicException('MySQL unexpectedly accepted case-colliding table names.');
        } catch (PDOException $error) {
            expect($error->errorInfo[1])->toBe(1050);
        }
    }
    $pdo->exec('DROP TABLE `'.$lower.'`');
    $pdo->exec('CREATE TABLE `'.$upper.'` (`id` int NULL) ENGINE=InnoDB');
    $after = $catalog->inspect();
    $comparison = (new MySqlComparator)->compare($after, $before);
    expect($comparison['errors'])->toBe([]);

    if ($setting === 1) {
        expect($comparison['differences'])->toBe([]);
    } else {
        $operations = array_column($comparison['differences'], null, 'kind');
        expect($operations['create_table']['automatic'])->toBe($setting === 0)
            ->and($operations['create_table']['sql'] !== [])->toBe($setting === 0)
            ->and($operations['extra_table']['automatic'])->toBeFalse();
    }
});

it('recognizes the configured migration ledger using the server table name rules', function (): void {
    $pdo = $this->identifierPdo;
    $name = $this->identifierTable;
    $pdo->exec('CREATE TABLE `'.$name.'` (`id` int unsigned AUTO_INCREMENT PRIMARY KEY, `migration` varchar(255) NOT NULL, `batch` int NOT NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO `'.$name."` (`migration`, `batch`) VALUES ('2026_01_01_000000_example', 1)");
    $snapshot = (new MySqlCatalog($pdo, strtoupper($name)))->inspect();
    $caseInsensitive = $snapshot->platform['settings']['lower_case_table_names'] !== 0;

    expect($snapshot->migrationNames)->toBe($caseInsensitive ? ['2026_01_01_000000_example'] : [])
        ->and(array_key_exists('@database.'.$name, $snapshot->tables))->toBe(! $caseInsensitive);
});

it('rejects migration ledger aliases of the replay marker only in case insensitive table namespaces', function (string $ledger): void {
    $pdo = $this->identifierPdo;
    $setting = (int) $pdo->query('SELECT @@lower_case_table_names')->fetchColumn();
    $catalog = new MySqlCatalog($pdo, $ledger);

    if ($setting === 0) {
        expect($catalog->inspect()->migrationNames)->toBe([]);
    } else {
        expect(fn () => $catalog->inspect())->toThrow(RuntimeException::class, 'replay ownership marker');
        expect($pdo->inTransaction())->toBeFalse();
    }
})->with(['__SCHEMA_ALIGN_RUN', '__schema_alİgn_run']);
