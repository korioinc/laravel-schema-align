<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Replay\ReplayNamespace;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Snapshot;

beforeEach(function (): void {
    $dsn = getenv('SCHEMA_ALIGN_MYSQL_DSN');

    if ($dsn) {
        // Separate test cases share the original MySQL catalog and discovery; sessions within each case remain independent.
        $this->namespaceDatabaseLock = fopen(sys_get_temp_dir().'/schema-align-mysql-'.hash('sha256', $dsn).'.lock', 'c');

        if ($this->namespaceDatabaseLock === false || ! flock($this->namespaceDatabaseLock, LOCK_EX)) {
            throw new RuntimeException('Cannot serialize disposable MySQL namespace fixtures.');
        }
    }
});

afterEach(function (): void {
    if (isset($this->namespaceDatabaseLock) && is_resource($this->namespaceDatabaseLock)) {
        flock($this->namespaceDatabaseLock, LOCK_UN);
        fclose($this->namespaceDatabaseLock);
    }
});

/** @return array{PDO, Closure(): PDO, array<string, mixed>, array<string, mixed>, list<string>} */
function namespaceOwnershipFixture(string $engine, bool $admin = true): array
{
    $mysql = $engine === 'mysql';
    $dsn = getenv($mysql ? 'SCHEMA_ALIGN_MYSQL_DSN' : 'SCHEMA_ALIGN_TEST_DSN');

    if (! $dsn) {
        test()->markTestSkipped('Explicit disposable loopback database is required.');
    }
    $pattern = $mysql
        ? '/\Amysql:host=(127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=(schema-align-tests(?:-[a-z0-9]+)?)\z/D'
        : '/\Apgsql:host=(127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=(schema-align-tests)\z/D';
    $user = getenv($mysql ? 'SCHEMA_ALIGN_MYSQL_USER' : 'SCHEMA_ALIGN_TEST_USER');

    if (! preg_match($pattern, $dsn, $parts) || $user !== 'schema_align_test' || (int) $parts[2] < 1 || (int) $parts[2] > 65535) {
        throw new RuntimeException('Namespace tests require an explicitly isolated loopback fixture.');
    }
    $password = getenv($mysql ? 'SCHEMA_ALIGN_MYSQL_PASSWORD' : 'SCHEMA_ALIGN_TEST_PASSWORD') ?: null;

    if ($mysql && $admin) {
        if (getenv('SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS') !== '1') {
            test()->markTestSkipped('Explicit admin opt-in is required to prove complete MySQL metadata visibility.');
        }
        $user = getenv('SCHEMA_ALIGN_MYSQL_ADMIN_USER');
        $password = getenv('SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD');

        if (! is_string($user) || $user === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Explicit disposable MySQL admin credentials are required.');
        }
    }
    $connect = static fn (): PDO => new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo = $connect();
    $identity = $mysql
        ? $pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS `database`')->fetch(PDO::FETCH_ASSOC)
        : $pdo->query('SELECT current_database() AS database, oid::text AS database_oid FROM pg_catalog.pg_database WHERE datname=current_database()')->fetch(PDO::FETCH_ASSOC);

    return [$pdo, $connect, ['instance' => 'namespace-tests', 'database' => $parts[3]], $identity, $mysql ? ['@database'] : ['public']];
}

it('replays and removes MySQL ownership markers under primary key policies', function (bool $generate, bool $require): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('mysql');

    if ($generate && $pdo->query("SHOW SESSION VARIABLES WHERE Variable_name = 'sql_generate_invisible_primary_key'")->fetchAll() === []) {
        $this->markTestSkipped('Generated invisible primary keys require MySQL 8.0.30 or later.');
    }
    $policy = static function (PDO $connection) use ($generate, $require): PDO {
        if ($generate) {
            $connection->exec('SET SESSION sql_generate_invisible_primary_key = ON');
        }
        $connection->exec('SET SESSION sql_require_primary_key = '.($require ? 'ON' : 'OFF'));

        return $connection;
    };
    $policy($pdo);
    $names = static fn (): array => $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'align-%' ORDER BY SCHEMA_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $before = $names();
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $worker = null;
    $session = null;

    try {
        $resource = $manager->create();
        $owned = '`'.$resource['namespaces'][0].'`';
        $worker = new ReplayNamespace($policy($connect()), $binding, $identity, $schemas);
        $worker->workerLease($resource);
        $session = $policy($connect());
        $session->exec('USE '.$owned);
        $worker->registerSession($resource, $session);
        $session->exec('CREATE TABLE replayed (id integer PRIMARY KEY, note varchar(100))');
        $session->exec("INSERT INTO replayed VALUES (1, 'retained during replay')");
        $current = json_decode($session->query('SELECT payload FROM __schema_align_run')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        $snapshot = (new MySqlCatalog($session, 'migrations', $current))->inspect();

        expect($current['state'])->toBe('running')
            ->and($current['sessions'])->not->toBeEmpty()
            ->and(array_keys($snapshot->tables))->toBe(['@database.replayed'])
            ->and($snapshot->unsupported)->toBe([])
            ->and($session->query('SELECT note FROM replayed WHERE id=1')->fetchColumn())->toBe('retained during replay');
        $session = null;
        $worker->releaseWorker();
        $manager->release();
        $recovered = new ReplayNamespace($policy($connect()), $binding, $identity, $schemas);

        expect($recovered->cleanup($resource['run_id'])['status'])->toBe('cleaned')
            ->and($recovered->cleanupOwned()['status'])->toBe('already_absent')
            ->and($names())->toBe($before);
    } finally {
        $session = null;
        $worker?->releaseWorker();
        $manager->release();
        // Fixtures serialize this disposable server; include failed allocations in teardown.
        foreach (array_diff($names(), $before) as $name) {
            $pdo->exec('DROP DATABASE `'.str_replace('`', '``', $name).'`');
        }
    }
})->with([
    'generated invisible primary keys' => [true, false],
    'required primary keys' => [false, true],
    'both primary key policies' => [true, true],
]);

it('still inspects and cleans legacy MySQL markers without a primary key', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('mysql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $owned = '`'.$resource['namespaces'][0].'`';
    $reader = null;

    try {
        $payload = $pdo->query('SELECT payload FROM '.$owned.'.__schema_align_run')->fetchColumn();
        $pdo->exec('DROP TABLE '.$owned.'.__schema_align_run');
        $pdo->exec('CREATE TABLE '.$owned.'.__schema_align_run (payload LONGTEXT NOT NULL) ENGINE=InnoDB');
        $statement = $pdo->prepare('INSERT INTO '.$owned.'.__schema_align_run (payload) VALUES (?)');
        $statement->execute([$payload]);
        $reader = $connect();
        $reader->exec('SET SESSION sql_require_primary_key = ON');
        $reader->exec('USE '.$owned);
        expect((new MySqlCatalog($reader, 'migrations', $resource))->inspect()->tables)->toBe([]);
        $reader = null;
        $manager->release();
        $pdo->exec('SET SESSION sql_require_primary_key = ON');
        $recovered = new ReplayNamespace($pdo, $binding, $identity, $schemas);

        expect($recovered->cleanupOwned()['status'])->toBe('cleaned')
            ->and($recovered->cleanup($resource['run_id'])['status'])->toBe('already_absent');
    } finally {
        $reader = null;
        $manager->release();
        $pdo->exec('DROP DATABASE IF EXISTS '.$owned);
    }
});

it('recovers durable ownership from a fresh manager and removes only its run', function (string $engine): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture($engine);
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $first = $manager->create();
    $second = $manager->create();
    $quote = $engine === 'mysql' ? '`' : '"';
    $table = $quote.$second['namespaces'][0].$quote.'.sentinel';

    try {
        $pdo->exec('CREATE TABLE '.$table.' (value integer)');
        $pdo->exec('INSERT INTO '.$table.' VALUES (37)');
        $manager->release();
        $recovered = new ReplayNamespace($connect(), $binding, $identity, $schemas);
        $recovered->cleanup($first['run_id']);
        $recovered->cleanup($first['run_id']);
        expect((int) $pdo->query('SELECT value FROM '.$table)->fetchColumn())->toBe(37);
        foreach ($first['namespaces'] as $name) {
            $statement = $pdo->prepare($engine === 'mysql' ? 'SELECT count(*) FROM information_schema.schemata WHERE schema_name=?' : 'SELECT count(*) FROM pg_catalog.pg_namespace WHERE nspname=?');
            $statement->execute([$name]);
            expect((int) $statement->fetchColumn())->toBe(0);
        }
    } finally {
        $manager->cleanup($first['run_id']);
        $manager->cleanup($second['run_id']);
        $manager->release();
    }
})->with(['pgsql', 'mysql']);

it('recovers numeric logical PostgreSQL schemas by run id and owned discovery', function (bool $discover): void {
    [$pdo, $connect, $binding, $identity] = namespaceOwnershipFixture('pgsql');
    $schema = (string) random_int(100000000, 999999999);
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');
    $resource = null;
    $manager = new ReplayNamespace($pdo, $binding, $identity, [$schema]);

    try {
        $pdo->exec('CREATE TABLE "'.$schema.'".sentinel (value integer)');
        $pdo->exec('INSERT INTO "'.$schema.'".sentinel VALUES (37)');
        $resource = $manager->create();
        $manager->release();
        $recovered = new ReplayNamespace($connect(), $binding, $identity, [$schema]);
        $discover ? $recovered->cleanupOwned() : $recovered->cleanup($resource['run_id']);
        foreach ($resource['namespaces'] as $physical) {
            expect($pdo->query('SELECT count(*) FROM pg_namespace WHERE nspname='.$pdo->quote($physical))->fetchColumn())->toBe(0);
        }
        expect($pdo->query('SELECT value FROM "'.$schema.'".sentinel')->fetchColumn())->toBe(37);
    } finally {
        $manager->release();
        foreach ($resource['namespaces'] ?? [] as $physical) {
            $pdo->exec('DROP SCHEMA IF EXISTS "'.$physical.'" CASCADE');
        }
        $pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');
    }
})->with(['run id' => false, 'owned discovery' => true]);

it('preserves resources while an independent worker or registered connection survives', function (string $engine): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture($engine);
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $worker = new ReplayNamespace($connect(), $binding, $identity, $schemas);
    $session = $connect();

    try {
        $worker->workerLease($resource);

        if ($engine === 'mysql') {
            $session->exec('USE `'.$resource['schema_map']['@database'].'`');
        }
        $worker->registerSession($resource, $session);
        $worker->registerSession($resource, $session);
        $manager->release();
        expect(fn () => $manager->cleanup($resource['run_id']))->toThrow(RuntimeException::class);
        $worker->releaseWorker();
        expect(fn () => $worker->registerSession($resource, $session))->toThrow(RuntimeException::class);
        expect(fn () => $manager->cleanup($resource['run_id']))->toThrow(RuntimeException::class);
        $quote = $engine === 'mysql' ? '`' : '"';
        $session->exec('CREATE TABLE '.$quote.$resource['namespaces'][0].$quote.'.survived (value integer)');
        $session->exec('INSERT INTO '.$quote.$resource['namespaces'][0].$quote.'.survived VALUES (43)');
        expect((int) $session->query('SELECT value FROM '.$quote.$resource['namespaces'][0].$quote.'.survived')->fetchColumn())->toBe(43);
    } finally {
        $session = null;
        $worker->releaseWorker();
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
})->with(['pgsql', 'mysql']);

it('rechecks the selected MySQL database for an already registered worker session', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('mysql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $first = $manager->create();
    $second = $manager->create();
    $worker = new ReplayNamespace($connect(), $binding, $identity, $schemas);
    $session = $connect();

    try {
        $worker->workerLease($first);
        $session->exec('USE `'.$first['schema_map']['@database'].'`');
        $worker->registerSession($first, $session);
        $worker->registerSession($first, $session);
        $session->exec('USE `'.$second['schema_map']['@database'].'`');
        $rejected = false;

        try {
            $worker->registerSession($first, $session);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $session->exec('USE `'.$first['schema_map']['@database'].'`');
        $worker->registerSession($first, $session);
    } finally {
        $session = null;
        $worker->releaseWorker();
        $manager->cleanup($first['run_id']);
        $manager->cleanup($second['run_id']);
        $manager->release();
    }
    expect($rejected)->toBeTrue();
});

it('recovers ownership when cleanup identity keys have a different order', function (string $engine): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture($engine);
    $identity = (new Snapshot($identity, [], []))->identity;
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $manager->release();

    try {
        $recovered = new ReplayNamespace($connect(), $binding, array_reverse($identity, true), $schemas);
        expect($recovered->cleanup($resource['run_id'])['status'])->toBe('cleaned');
        foreach ($resource['namespaces'] as $name) {
            $statement = $pdo->prepare($engine === 'mysql' ? 'SELECT count(*) FROM information_schema.schemata WHERE schema_name=?' : 'SELECT count(*) FROM pg_catalog.pg_namespace WHERE nspname=?');
            $statement->execute([$name]);
            expect((int) $statement->fetchColumn())->toBe(0);
        }
    } finally {
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
})->with(['pgsql', 'mysql']);

it('preserves resources when cleanup identity values change type', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('pgsql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $owned = '"'.$resource['namespaces'][0].'"';

    try {
        $pdo->exec('CREATE TABLE '.$owned.'.retained (value integer)');
        $pdo->exec('INSERT INTO '.$owned.'.retained VALUES (97)');
        $manager->release();
        $changedIdentity = [...$identity, 'database_oid' => (int) $identity['database_oid']];
        $recovered = new ReplayNamespace($connect(), $binding, $changedIdentity, $schemas);
        expect(fn () => $recovered->cleanup($resource['run_id']))->toThrow(RuntimeException::class)
            ->and((int) $pdo->query('SELECT value FROM '.$owned.'.retained')->fetchColumn())->toBe(97);
    } finally {
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
});

it('rolls back PostgreSQL cleanup when an external view depends on an owned table', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('pgsql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $owned = '"'.$resource['namespaces'][0].'"';
    $external = '"sa_namespace_'.bin2hex(random_bytes(8)).'"';
    $pdo->exec('CREATE SCHEMA '.$external);

    try {
        $pdo->exec('CREATE TABLE '.$owned.'.source (value integer)');
        $pdo->exec('INSERT INTO '.$owned.'.source VALUES (59)');
        $pdo->exec('CREATE VIEW '.$external.'.retained AS SELECT * FROM '.$owned.'.source');
        expect(fn () => $manager->cleanup($resource['run_id']))->toThrow(RuntimeException::class);
        expect((int) $pdo->query('SELECT value FROM '.$external.'.retained')->fetchColumn())->toBe(59);
    } finally {
        $pdo->exec('DROP VIEW IF EXISTS '.$external.'.retained');
        $pdo->exec('DROP SCHEMA '.$external.' RESTRICT');
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
});

it('refuses a changed ownership marker without deleting persisted data', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('pgsql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $owned = '"'.$resource['namespaces'][0].'"';
    $marker = $pdo->query('SELECT obj_description('.(int) $resource['namespace_oids'][$resource['namespaces'][0]].", 'pg_namespace')")->fetchColumn();

    try {
        $pdo->exec('CREATE TABLE '.$owned.'.retained (value integer)');
        $pdo->exec('INSERT INTO '.$owned.'.retained VALUES (61)');
        $changed = json_decode($marker, true, 512, JSON_THROW_ON_ERROR);
        $changed['version'] = 999;
        $pdo->exec('COMMENT ON SCHEMA '.$owned.' IS '.$pdo->quote(json_encode($changed, JSON_THROW_ON_ERROR)));
        expect(fn () => $manager->cleanup($resource['run_id']))->toThrow(RuntimeException::class);
        expect((int) $pdo->query('SELECT value FROM '.$owned.'.retained')->fetchColumn())->toBe(61);
    } finally {
        $pdo->exec('COMMENT ON SCHEMA '.$owned.' IS '.$pdo->quote($marker));
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
});

it('refuses uncertain MySQL cleanup and preserves data under restricted metadata privileges', function (): void {
    // Administrative recovery is explicit test infrastructure, never production credential fallback.
    [$admin, $adminConnect] = namespaceOwnershipFixture('mysql');
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('mysql', false);
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $table = '`sa_namespace_retained_'.bin2hex(random_bytes(8)).'`';

    try {
        $pdo->exec('CREATE TABLE '.$table.' (value integer)');
        $pdo->exec('INSERT INTO '.$table.' VALUES (67)');
        // Remove one visibility capability only for this serialized disposable account.
        $account = $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $separator = strrpos($account, '@');
        $grantee = $admin->quote(substr($account, 0, $separator)).'@'.$admin->quote(substr($account, $separator + 1));
        $hadProcess = in_array('PROCESS', TargetConnection::mysqlGlobalPrivileges($pdo), true);

        if ($hadProcess) {
            $admin->exec('REVOKE PROCESS ON *.* FROM '.$grantee);
        }
        expect(fn () => $manager->create())->toThrow(RuntimeException::class);
        expect((int) $pdo->query('SELECT value FROM '.$table)->fetchColumn())->toBe(67);
    } finally {
        if (isset($hadProcess, $grantee) && $hadProcess) {
            $admin->exec('GRANT PROCESS ON *.* TO '.$grantee);
        }
        $manager->release();
        $admin->exec('DROP TABLE '.$table);
    }
});

it('replays and cleans owned MySQL resources despite unrelated marker tables', function (bool $namespaceName, bool $generated): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('mysql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $first = $manager->create();
    $second = $manager->create();
    $outside = $namespaceName ? 'align-0000-'.substr(bin2hex(random_bytes(3)), 0, 5) : 'sa_unrelated_'.bin2hex(random_bytes(8));
    $outsideCreated = false;
    $worker = new ReplayNamespace($connect(), $binding, $identity, $schemas);
    $session = $connect();

    try {
        $pdo->exec('CREATE DATABASE `'.$outside.'`');
        $outsideCreated = true;
        $table = '`'.$outside.'`.`__schema_align_run`';
        $column = $generated ? ', payload LONGTEXT GENERATED ALWAYS AS (CONCAT('.$pdo->quote($first['run_id']).', value)) STORED' : '';
        $pdo->exec('CREATE TABLE '.$table.' (value integer NOT NULL'.$column.')');
        $pdo->exec('INSERT INTO '.$table.' (value) VALUES (53)');
        $pdo->exec('CREATE TABLE `'.$second['namespaces'][0].'`.retained (value integer)');
        $pdo->exec('INSERT INTO `'.$second['namespaces'][0].'`.retained VALUES (37)');

        $worker->workerLease($first);
        $session->exec('USE `'.$first['schema_map']['@database'].'`');
        $worker->registerSession($first, $session);
        $session->exec('CREATE TABLE `'.$first['namespaces'][0].'`.replayed (value integer)');
        $session->exec('INSERT INTO `'.$first['namespaces'][0].'`.replayed VALUES (41)');
        expect((int) $session->query('SELECT value FROM `'.$first['namespaces'][0].'`.replayed')->fetchColumn())->toBe(41);
        $session = null;
        $worker->releaseWorker();
        $manager->release();

        $recovered = new ReplayNamespace($connect(), $binding, $identity, $schemas);
        $recovered->cleanup($first['run_id']);
        $statement = $pdo->prepare('SELECT count(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $statement->execute([$first['namespaces'][0]]);
        expect((int) $statement->fetchColumn())->toBe(0)
            ->and((int) $pdo->query('SELECT value FROM `'.$second['namespaces'][0].'`.retained')->fetchColumn())->toBe(37);
        $recovered->cleanupOwned();
        $statement->execute([$second['namespaces'][0]]);
        expect((int) $statement->fetchColumn())->toBe(0)
            ->and((int) $pdo->query('SELECT value FROM '.$table)->fetchColumn())->toBe(53);
    } finally {
        $session = null;
        $worker->releaseWorker();

        if ($outsideCreated) {
            $pdo->exec('DROP DATABASE `'.$outside.'`');
        }
        $manager->cleanup($first['run_id']);
        $manager->cleanup($second['run_id']);
        $manager->release();
    }
})->with([
    'unrelated database without payload' => [false, false],
    'namespace-shaped database without payload' => [true, false],
    'namespace-shaped database with generated payload' => [true, true],
]);

it('retains a MySQL resource with a malformed marker while another run remains discoverable', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('mysql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $other = $manager->create();
    $owned = '`'.$resource['namespaces'][0].'`';

    try {
        $pdo->exec('CREATE TABLE '.$owned.'.retained (value integer)');
        $pdo->exec('INSERT INTO '.$owned.'.retained VALUES (61)');
        $pdo->exec('ALTER TABLE '.$owned.'.`__schema_align_run` RENAME COLUMN payload TO untrusted_payload');
        $manager->release();
        $recovered = new ReplayNamespace($connect(), $binding, $identity, $schemas);
        expect(fn () => $recovered->workerLease($resource))->toThrow(RuntimeException::class)
            ->and(fn () => $recovered->cleanup($resource['run_id']))->toThrow(RuntimeException::class)
            ->and((int) $pdo->query('SELECT value FROM '.$owned.'.retained')->fetchColumn())->toBe(61);
        $recovered->cleanup($other['run_id']);
        $statement = $pdo->prepare('SELECT count(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $statement->execute([$other['namespaces'][0]]);
        expect((int) $statement->fetchColumn())->toBe(0);
    } finally {
        $pdo->exec('ALTER TABLE '.$owned.'.`__schema_align_run` RENAME COLUMN untrusted_payload TO payload');
        $manager->cleanup($resource['run_id']);
        $manager->cleanup($other['run_id']);
        $manager->release();
    }
});

it('preserves an external MySQL foreign key and its rows during cleanup refusal', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('mysql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $owned = '`'.$resource['namespaces'][0].'`.parent';
    $external = '`sa_namespace_'.bin2hex(random_bytes(8)).'`';

    try {
        $pdo->exec('CREATE TABLE '.$owned.' (id integer PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.$owned.' VALUES (71)');
        $pdo->exec('CREATE TABLE '.$external.' (id integer PRIMARY KEY, FOREIGN KEY (id) REFERENCES '.$owned.' (id)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.$external.' VALUES (71)');
        expect(fn () => $manager->cleanup($resource['run_id']))->toThrow(RuntimeException::class);
        expect((int) $pdo->query('SELECT id FROM '.$external)->fetchColumn())->toBe(71)
            ->and((int) $pdo->query('SELECT id FROM '.$owned)->fetchColumn())->toBe(71)
            ->and(fn () => $pdo->exec('INSERT INTO '.$external.' VALUES (73)'))->toThrow(PDOException::class);
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS '.$external);
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
});

it('cleans PostgreSQL tables with cross-namespace internal foreign keys while retaining original data', function (): void {
    [$pdo, $connect, $binding, $identity] = namespaceOwnershipFixture('pgsql');
    $first = 'sa_namespace_a_'.bin2hex(random_bytes(8));
    $second = 'sa_namespace_b_'.bin2hex(random_bytes(8));
    $pdo->exec('CREATE SCHEMA "'.$first.'"');
    $pdo->exec('CREATE SCHEMA "'.$second.'"');
    $manager = new ReplayNamespace($pdo, $binding, $identity, [$first, $second]);
    $resource = null;

    try {
        $pdo->exec('CREATE TABLE "'.$first.'".retained (id integer)');
        $pdo->exec('INSERT INTO "'.$first.'".retained VALUES (79)');
        $resource = $manager->create();
        $a = '"'.$resource['schema_map'][$first].'"';
        $b = '"'.$resource['schema_map'][$second].'"';
        $pdo->exec('CREATE TABLE '.$a.'.parent (id integer PRIMARY KEY)');
        $pdo->exec('CREATE TABLE '.$b.'.child (id integer REFERENCES '.$a.'.parent(id))');
        $manager->cleanup($resource['run_id']);
        expect((int) $pdo->query('SELECT id FROM "'.$first.'".retained')->fetchColumn())->toBe(79);
        foreach ($resource['namespaces'] as $name) {
            $statement = $pdo->prepare('SELECT count(*) FROM pg_catalog.pg_namespace WHERE nspname=?');
            $statement->execute([$name]);
            expect((int) $statement->fetchColumn())->toBe(0);
        }
    } finally {
        if ($resource !== null) {
            $manager->cleanup($resource['run_id']);
        }
        $manager->release();
        $pdo->exec('DROP TABLE IF EXISTS "'.$first.'".retained');
        $pdo->exec('DROP SCHEMA "'.$first.'" RESTRICT');
        $pdo->exec('DROP SCHEMA "'.$second.'" RESTRICT');
    }
});

it('preserves populated external PostgreSQL partitions when their owned parent is unsupported', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('pgsql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $owned = '"'.$resource['namespaces'][0].'"';
    $outside = '"sa_partition_'.bin2hex(random_bytes(8)).'"';
    $pdo->exec('CREATE SCHEMA '.$outside);

    try {
        $pdo->exec('CREATE TABLE '.$owned.'.parent (id integer) PARTITION BY RANGE (id)');
        $pdo->exec('CREATE TABLE '.$outside.'.retained PARTITION OF '.$owned.'.parent FOR VALUES FROM (0) TO (100)');
        $pdo->exec('INSERT INTO '.$outside.'.retained VALUES (83)');
        expect(fn () => $manager->cleanup($resource['run_id']))->toThrow(RuntimeException::class);
        expect((int) $pdo->query('SELECT id FROM '.$outside.'.retained')->fetchColumn())->toBe(83);
    } finally {
        $pdo->exec('DROP TABLE '.$outside.'.retained');
        $pdo->exec('DROP TABLE '.$owned.'.parent');
        $pdo->exec('DROP SCHEMA '.$outside.' RESTRICT');
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
});

it('does not report missing ownership markers as absent while an exact-run candidate retains data', function (): void {
    [$pdo, $connect, $binding, $identity, $schemas] = namespaceOwnershipFixture('pgsql');
    $manager = new ReplayNamespace($pdo, $binding, $identity, $schemas);
    $resource = $manager->create();
    $owned = '"'.$resource['namespaces'][0].'"';
    $marker = json_encode($resource, JSON_THROW_ON_ERROR);

    try {
        $pdo->exec('CREATE TABLE '.$owned.'.retained (value integer)');
        $pdo->exec('INSERT INTO '.$owned.'.retained VALUES (89)');
        $pdo->exec('COMMENT ON SCHEMA '.$owned.' IS NULL');
        $manager->release();
        $fresh = new ReplayNamespace($connect(), $binding, $identity, $schemas);
        expect(fn () => $fresh->cleanup($resource['run_id']))->toThrow(RuntimeException::class);
        expect((int) $pdo->query('SELECT value FROM '.$owned.'.retained')->fetchColumn())->toBe(89);
    } finally {
        $pdo->exec('COMMENT ON SCHEMA '.$owned.' IS '.$pdo->quote($marker));
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
});
