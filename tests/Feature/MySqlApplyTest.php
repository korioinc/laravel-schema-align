<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Execution\MySqlApplyExecutor;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Tests\Support\MySqlFixture;

beforeEach(function (): void {
    if (! getenv('SCHEMA_ALIGN_MYSQL_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_MYSQL_DSN for isolated MySQL apply tests.');
    }
    $this->mysqlFixture = new MySqlFixture;
});

afterEach(function (): void {
    if (isset($this->mysqlFixture)) {
        $this->mysqlFixture->close();
    }
});

it('rejects changed MySQL session encodings before reviewing or applying signed DDL', function (string $setting): void {
    $f = $this->mysqlFixture;
    $f->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $before = $f->inspect();
    $sql = 'ALTER TABLE `'.$f->table.'` ADD COLUMN `café` text NULL';
    $f->pdo->exec($sql);
    $after = $f->inspect();
    $f->pdo->exec('ALTER TABLE `'.$f->table.'` DROP COLUMN `café`');
    $plan = $f->store->save([
        'engine' => 'mysql', 'execution_mode' => 'checkpointed', 'schemas' => ['@database'],
        'migration_table' => 'migrations', 'source' => ['revision' => 'session-encoding'],
        'binding' => $f->binding, 'identity' => $before->identity,
        'before_hash' => $before->fingerprint(), 'after_hash' => $after->fingerprint(),
        'operations' => [['automatic' => true, 'transactional' => false, 'sql' => [$sql],
            'before_hash' => $before->fingerprint(), 'after_hash' => $after->fingerprint()]],
    ]);
    $executor = new MySqlApplyExecutor($f->pdo, $f->store);
    $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);
    $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $f->pdo->exec($setting);

    try {
        expect(fn () => $executor->review($plan['id'], $plan['signature'], $f->binding))
            ->toThrow(RuntimeException::class, 'Stale or divergent MySQL plan');
        expect(fn () => $f->apply($plan))->toThrow(RuntimeException::class, 'Stale or divergent MySQL plan')
            ->and($f->inspect()->tables)->toBe($before->tables)
            ->and(file_get_contents($journalPath))->toBe($journal)
            ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
    } finally {
        $f->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    expect($f->apply($plan)['status'])->toBe('applied')
        ->and($f->inspect()->tables['@database.'.$f->table]['columns'])->toHaveKey('café')->not->toHaveKey('cafÃ©');
})->with([
    'client charset' => 'SET SESSION character_set_client = latin1',
    'connection charset' => 'SET SESSION character_set_connection = latin1',
    'result charset' => 'SET SESSION character_set_results = latin1',
    'unconverted results' => 'SET SESSION character_set_results = NULL',
    'connection collation' => 'SET SESSION collation_connection = utf8mb4_bin',
    'all connection encodings' => 'SET NAMES latin1',
]);

it('requires a valid administrator approval and preserves existing rows through apply', function (): void {
    if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1'
        || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER') || ! getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD')) {
        $this->markTestSkipped('Explicit disposable administrator credentials are required for authorization proof.');
    }
    $f = $this->mysqlFixture;
    $admin = new PDO(getenv('SCHEMA_ALIGN_MYSQL_DSN'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER'), getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
    $inspect = fn () => (new MySqlCatalog($admin))->inspect();
    $plan = $f->plan();
    // Catalog identity and fingerprints must come from the approving account.
    $before = $inspect();
    $admin->exec($plan['operations'][0]['sql'][0]);
    $after = $inspect();
    $admin->exec('ALTER TABLE `'.$f->table.'` DROP COLUMN note');
    unset($plan['signature']);
    $binding = array_replace($f->binding, ['username' => getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER')]);
    $plan['binding'] = $binding;
    $plan['identity'] = $before->identity;
    $plan['before_hash'] = $plan['operations'][0]['before_hash'] = $before->fingerprint();
    $plan['after_hash'] = $plan['operations'][0]['after_hash'] = $after->fingerprint();
    $plan = $f->store->save($plan);
    $executor = new MySqlApplyExecutor($admin, $f->store);
    $rows = $admin->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assertUnchanged = function (string $fingerprint) use ($admin, $f, $inspect, $rows): void {
        expect($inspect()->fingerprint())->toBe($fingerprint)
            ->and($admin->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
    };

    expect(fn () => $executor->apply($plan['id'], str_repeat('0', 64), $binding))->toThrow(RuntimeException::class);
    $assertUnchanged($before->fingerprint());
    expect(fn () => $executor->apply($plan['id'], $plan['signature'], array_replace($binding, ['instance' => 'another'])))->toThrow(RuntimeException::class);
    $assertUnchanged($before->fingerprint());

    $path = $f->directory.'/'.$plan['id'].'.plan.json';
    $edited = $plan;
    $edited['operations'][0]['sql'] = ['DELETE FROM `'.$f->table.'`'];
    file_put_contents($path, json_encode($edited, JSON_THROW_ON_ERROR));
    expect(fn () => $executor->apply($plan['id'], $plan['signature'], $binding))->toThrow(RuntimeException::class);
    $assertUnchanged($before->fingerprint());
    file_put_contents($path, json_encode($plan, JSON_THROW_ON_ERROR));

    $other = $plan;
    unset($other['signature']);
    $other['identity']['database'] = 'another';
    $other = $f->store->save($other);
    expect(fn () => $executor->apply($other['id'], $other['signature'], $binding))->toThrow(RuntimeException::class);
    $assertUnchanged($before->fingerprint());

    $admin->exec('ALTER TABLE `'.$f->table.'` ADD COLUMN external_change int NULL');
    $stale = $inspect()->fingerprint();
    expect(fn () => $executor->apply($plan['id'], $plan['signature'], $binding))->toThrow(RuntimeException::class);
    $assertUnchanged($stale);
    $admin->exec('ALTER TABLE `'.$f->table.'` DROP COLUMN external_change');

    $executor->apply($plan['id'], $plan['signature'], $binding);
    $admin->exec('SET SESSION transaction_read_only=OFF');
    $admin->exec('UPDATE `'.$f->table."` SET note='approved by administrator' WHERE id=1");
    expect($admin->query('SELECT note FROM `'.$f->table.'` WHERE id=1')->fetchColumn())->toBe('approved by administrator')
        ->and($admin->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
});

it('applies MySQL DDL and reconciles repeated approval while preserving data', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    $executor = new MySqlApplyExecutor($f->pdo, $f->store);
    $baseline = $f->inspect()->fingerprint();
    $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);
    $executor->review($plan['id'], $plan['signature'], $f->binding);
    expect($f->inspect()->fingerprint())->toBe($baseline)
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows)
        ->and(file_get_contents($journalPath))->toBe($journal);
    $f->apply($plan);
    $f->pdo->exec('SET SESSION transaction_read_only = OFF');
    $f->pdo->exec("UPDATE `{$f->table}` SET note = 'retained' WHERE id = 1");
    $applied = $f->inspect()->fingerprint();
    $journal = file_get_contents($journalPath);
    $executor->review($plan['id'], $plan['signature'], $f->binding);
    expect($f->inspect()->fingerprint())->toBe($applied)
        ->and(file_get_contents($journalPath))->toBe($journal);
    $f->apply($plan);
    expect($f->pdo->query("SELECT note FROM `{$f->table}` WHERE id = 1")->fetchColumn())->toBe('retained')
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows)
        ->and($f->pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn())->toBe(0);
    // Reconciliation runs no DDL and preserves the caller's existing read-only setting.
    $f->pdo->exec('ALTER TABLE `'.$f->table.'` ADD COLUMN external_change int NULL');
    $divergent = $f->inspect()->fingerprint();
    $journal = file_get_contents($journalPath);
    expect(fn () => $executor->review($plan['id'], $plan['signature'], $f->binding))->toThrow(RuntimeException::class)
        ->and(file_get_contents($journalPath))->toBe($journal);
    expect(fn () => $f->apply($plan))->toThrow(RuntimeException::class)
        ->and($f->inspect()->fingerprint())->toBe($divergent)
        ->and($f->pdo->query("SELECT note FROM `{$f->table}` WHERE id = 1")->fetchColumn())->toBe('retained')
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
});

it('recovers a committed MySQL prefix only with explicit approval to resume', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan(true);
    $f->pdo->exec("UPDATE `{$f->table}` SET name = 'Sample item A' WHERE id = 2");
    expect(fn () => $f->apply($plan))->toThrow(PDOException::class)
        ->and($f->inspect()->fingerprint())->toBe($plan['operations'][0]['after_hash']);
    $executor = new MySqlApplyExecutor($f->pdo, $f->store);
    $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);
    expect(fn () => $executor->review($plan['id'], $plan['signature'], $f->binding))->toThrow(RuntimeException::class);
    $executor->review($plan['id'], $plan['signature'], $f->binding, true);
    expect($f->inspect()->fingerprint())->toBe($plan['operations'][0]['after_hash'])
        ->and(file_get_contents($journalPath))->toBe($journal);
    expect(fn () => $f->apply($plan))->toThrow(RuntimeException::class);
    $f->pdo->exec('SET SESSION transaction_read_only = OFF');
    $f->pdo->exec("UPDATE `{$f->table}` SET name = 'Sample item C', note = 'committed prefix retained' WHERE id = 2");
    $f->apply($plan, true);
    expect($f->pdo->query("SELECT note FROM `{$f->table}` WHERE id = 2")->fetchColumn())->toBe('committed prefix retained')
        ->and((int) $f->pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn())->toBe(1);
});

it('refuses divergent MySQL schemas and does not guess a recovery prefix', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    $executor = new MySqlApplyExecutor($f->pdo, $f->store);
    $executor->review($plan['id'], $plan['signature'], $f->binding);
    $f->pdo->exec('ALTER TABLE `'.$f->table.'` ADD COLUMN external_change int NULL');
    $hash = $f->inspect()->fingerprint();
    expect(fn () => $executor->apply($plan['id'], $plan['signature'], $f->binding, true))->toThrow(RuntimeException::class)
        ->and($f->inspect()->fingerprint())->toBe($hash);
    $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);
    expect(fn () => $executor->review($plan['id'], $plan['signature'], $f->binding, true))->toThrow(RuntimeException::class)
        ->and($f->inspect()->fingerprint())->toBe($hash)
        ->and(file_get_contents($journalPath))->toBe($journal)
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
});

it('requires explicit recovery for an unknown MySQL journal at baseline', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    $f->store->record($plan['id'], ['status' => 'unknown']);
    $executor = new MySqlApplyExecutor($f->pdo, $f->store);
    $baseline = $f->inspect()->fingerprint();
    $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);
    expect(fn () => $executor->review($plan['id'], $plan['signature'], $f->binding))->toThrow(RuntimeException::class);
    $executor->review($plan['id'], $plan['signature'], $f->binding, true);
    expect($f->inspect()->fingerprint())->toBe($baseline)
        ->and(file_get_contents($journalPath))->toBe($journal)
        ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
    expect(fn () => $f->apply($plan))->toThrow(RuntimeException::class);
    $f->apply($plan, true);
    $f->pdo->exec('SET SESSION transaction_read_only = OFF');
    $f->pdo->exec("UPDATE `{$f->table}` SET note = 'Recovered' WHERE id = 1");
    expect($f->pdo->query("SELECT note FROM `{$f->table}` WHERE id = 1")->fetchColumn())->toBe('Recovered');
});

it('rejects MySQL approval, binding, identity, and signed checkpoint inconsistencies before DDL', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    $baseline = $f->inspect()->fingerprint();
    $executor = new MySqlApplyExecutor($f->pdo, $f->store);
    expect(fn () => $executor->apply($plan['id'], str_repeat('0', 64), $f->binding))->toThrow(RuntimeException::class, 'Approval');
    expect(fn () => $executor->apply($plan['id'], $plan['signature'], array_replace($f->binding, ['instance' => 'another'])))->toThrow(RuntimeException::class, 'bound');
    $other = $plan;
    $other['identity']['database'] = 'another';
    unset($other['signature']);
    $other = $f->store->save($other);
    expect(fn () => $f->apply($other))->toThrow(RuntimeException::class, 'identity');
    $other = $plan;
    $other['operations'][0]['after_hash'] = $plan['before_hash'];
    $other['after_hash'] = $plan['before_hash'];
    unset($other['signature']);
    $other = $f->store->save($other);
    expect(fn () => $f->apply($other, true))->toThrow(RuntimeException::class, 'ambiguous')
        ->and($f->inspect()->fingerprint())->toBe($baseline);
});

it('rejects edited MySQL SQL and cross-engine plans', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    $path = $f->directory.'/'.$plan['id'].'.plan.json';
    $edited = $plan;
    $edited['operations'][0]['sql'] = ['DELETE FROM `'.$f->table.'`'];
    file_put_contents($path, json_encode($edited, JSON_THROW_ON_ERROR));
    expect(fn () => $f->apply($plan))->toThrow(RuntimeException::class, 'signature');
    unset($plan['signature']);
    $plan['engine'] = 'pgsql';
    $plan = $f->store->save($plan);
    expect(fn () => $f->apply($plan))->toThrow(RuntimeException::class, 'MySQL checkpointed');
});

it('reconciles a completed MySQL checkpoint even when the journal lost its final update', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    $f->apply($plan);
    $f->store->record($plan['id'], ['status' => 'executing', 'operation_index' => 0]);
    $result = $f->apply($plan);
    expect($result['status'])->toBe('applied')->and($result['reconciled'])->toBeTrue();
});

it('refuses overlapping MySQL package execution held by a separate connection', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    $pdo = new PDO(getenv('SCHEMA_ALIGN_MYSQL_DSN'), getenv('SCHEMA_ALIGN_MYSQL_USER'), getenv('SCHEMA_ALIGN_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lockName = 'schema-align:'.substr(hash('sha256', $f->binding['database']), 0, 50);
    $query = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $query->execute([$lockName]);
    expect((int) $query->fetchColumn())->toBe(1);

    try {
        expect(fn () => (new MySqlApplyExecutor($f->pdo, $f->store))->apply($plan['id'], $plan['signature'], $f->binding, false, 0, 0))->toThrow(RuntimeException::class, 'database lock')
            ->and($f->inspect()->fingerprint())->toBe($plan['before_hash']);
    } finally {
        $query = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$lockName]);
    }
    expect($f->apply($plan)['status'])->toBe('applied');
});

it('refuses manual, transactional, multi-statement operation arrays and broken checkpoint chains', function (): void {
    $f = $this->mysqlFixture;
    $plan = $f->plan();
    foreach ([['automatic' => false], ['transactional' => true], ['sql' => ['SELECT 1', 'SELECT 2']], ['before_hash' => str_repeat('0', 64)]] as $changes) {
        $invalid = $plan;
        unset($invalid['signature']);
        $invalid['operations'][0] = array_replace($invalid['operations'][0], $changes);
        $invalid = $f->store->save($invalid);
        expect(fn () => $f->apply($invalid))->toThrow(RuntimeException::class, 'one nontransactional SQL');
    }
    expect($f->inspect()->fingerprint())->toBe($plan['before_hash']);
});

it('preserves MySQL data and the journal when an independent session locks the ledger', function (string $action): void {
    $f = $this->mysqlFixture;
    $ledger = $f->table.'_ledger';
    $holder = new PDO(getenv('SCHEMA_ALIGN_MYSQL_DSN'), getenv('SCHEMA_ALIGN_MYSQL_USER'), getenv('SCHEMA_ALIGN_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $f->pdo->exec('CREATE TABLE `'.$ledger.'` (id int PRIMARY KEY, migration varchar(255) NOT NULL, batch int NOT NULL)');

    try {
        $f->pdo->exec("INSERT INTO `{$ledger}` VALUES (1, 'baseline', 1)");
        $inspect = fn () => (new MySqlCatalog($f->pdo, $ledger))->inspect();
        $plan = $f->plan();
        $before = $inspect();
        $f->pdo->exec($plan['operations'][0]['sql'][0]);
        $after = $inspect();
        $f->pdo->exec('ALTER TABLE `'.$f->table.'` DROP COLUMN note');
        unset($plan['signature']);
        $plan['migration_table'] = $ledger;
        $plan['before_hash'] = $plan['operations'][0]['before_hash'] = $before->fingerprint();
        $plan['after_hash'] = $plan['operations'][0]['after_hash'] = $after->fingerprint();
        $plan = $f->store->save($plan);
        $executor = new MySqlApplyExecutor($f->pdo, $f->store, $ledger);
        $rows = $f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
        $journal = file_get_contents($journalPath);
        $holder->exec('LOCK TABLES `'.$ledger.'` WRITE');

        try {
            $failure = null;

            try {
                $executor->{$action}($plan['id'], $plan['signature'], $f->binding, false, 20);
            } catch (PDOException $error) {
                $failure = $error;
            }
            $lockWaitTimeout = 1205;
            expect($failure)->toBeInstanceOf(PDOException::class)
                ->and($failure?->errorInfo[1])->toBe($lockWaitTimeout);
        } finally {
            $holder->exec('UNLOCK TABLES');
        }
        expect($inspect()->fingerprint())->toBe($before->fingerprint())
            ->and($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows)
            ->and(file_get_contents($journalPath))->toBe($journal);
        $executor->review($plan['id'], $plan['signature'], $f->binding);
        $executor->apply($plan['id'], $plan['signature'], $f->binding);
        $f->pdo->exec('SET SESSION transaction_read_only = OFF');
        $f->pdo->exec("UPDATE `{$f->table}` SET note = 'retry after ledger unlock' WHERE id = 1");
        expect($f->pdo->query('SELECT id, name FROM `'.$f->table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
    } finally {
        $f->pdo->exec('SET SESSION transaction_read_only = OFF');
        $f->pdo->exec('DROP TABLE `'.$ledger.'`');
    }
})->with(['review', 'apply']);
