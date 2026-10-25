<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Execution\ApplyExecutor;
use Korioinc\SchemaAlign\Planning\PlanStore;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\SchemaAlign;

beforeEach(function (): void {
    $dsn = getenv('SCHEMA_ALIGN_TEST_DSN');

    if (! $dsn) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN to the isolated PostgreSQL test database.');
    }

    if (! preg_match('/\Apgsql:host=(127\.0\.0\.1|localhost);port=([0-9]{1,5});dbname=(schema-align-tests)\z/D', $dsn, $parts)
        || (int) $parts[2] < 1 || (int) $parts[2] > 65535
        || getenv('SCHEMA_ALIGN_TEST_USER') !== 'schema_align_test') {
        throw new RuntimeException('Connection safety tests require the explicitly isolated loopback test database and restricted role.');
    }
    // Apply uses a database-wide lock; share the integration fixtures' worker serialization.
    $this->targetDatabaseLock = fopen(sys_get_temp_dir().'/schema-align-tests-'.hash('sha256', $dsn).'.lock', 'c');

    if ($this->targetDatabaseLock === false || ! flock($this->targetDatabaseLock, LOCK_EX)) {
        throw new RuntimeException('Cannot serialize disposable PostgreSQL apply fixtures.');
    }
    $this->targetConfig = [
        'driver' => 'pgsql', 'host' => $parts[1], 'port' => (int) $parts[2],
        'database' => $parts[3], 'username' => 'schema_align_test',
        'password' => getenv('SCHEMA_ALIGN_TEST_PASSWORD') ?: null,
        'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'prefer',
    ];
    $this->targetDsn = $dsn;
    $this->targetWriter = new PDO($dsn, 'schema_align_test', $this->targetConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if ($this->targetWriter->query('SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname = current_user')->fetchColumn()) {
        throw new RuntimeException('The fixture owner must be a restricted role.');
    }
    $this->targetSchema = 'sa_connection_'.bin2hex(random_bytes(8));
    $this->targetQuotedSchema = '"'.$this->targetSchema.'"';
    $this->targetDirectory = sys_get_temp_dir().'/'.$this->targetSchema;
    $this->targetSessions = [];
    $this->targetWriter->exec('CREATE SCHEMA '.$this->targetQuotedSchema);
    $this->targetWriter->exec('CREATE TABLE '.$this->targetQuotedSchema.'.sample_items (id integer PRIMARY KEY, name text NOT NULL)');
    $this->targetWriter->exec("INSERT INTO {$this->targetQuotedSchema}.sample_items VALUES (1, 'Sample item A')");
});

afterEach(function (): void {
    foreach ($this->targetSessions ?? [] as $pdo) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    if (isset($this->targetSchema)) {
        $this->targetWriter->exec('DROP SCHEMA '.$this->targetQuotedSchema.' CASCADE');
        (new Filesystem)->deleteDirectory($this->targetDirectory);
    }

    if (isset($this->targetDatabaseLock) && is_resource($this->targetDatabaseLock)) {
        flock($this->targetDatabaseLock, LOCK_UN);
        fclose($this->targetDatabaseLock);
    }
});

describe('explicitly enabled isolated superuser safety', function (): void {
    beforeEach(function (): void {
        if (getenv('SCHEMA_ALIGN_SUPERUSER_TESTS') !== '1') {
            $this->markTestSkipped('Set SCHEMA_ALIGN_SUPERUSER_TESTS=1 to enable isolated admin safety tests.');
        }
        $username = getenv('SCHEMA_ALIGN_TEST_ADMIN_USER');
        $password = getenv('SCHEMA_ALIGN_TEST_ADMIN_PASSWORD');

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Explicit isolated test admin credentials are required.');
        }
        $this->adminConfig = array_replace($this->targetConfig, ['username' => $username, 'password' => $password]);
        $this->adminPdo = new PDO($this->targetDsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->targetSessions[] = $this->adminPdo;

        if (! $this->adminPdo->query('SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname = session_user')->fetchColumn()) {
            throw new RuntimeException('This safety scenario requires a real superuser test session.');
        }
    });

    it('permits superuser catalog inspection while rejecting DML and DDL without changing target data', function (): void {
        $pdo = TargetConnection::readOnly($this->adminConfig, 'isolated-connection-tests');
        $this->targetSessions[] = $pdo;
        $catalog = new PostgresCatalog($this->targetWriter, [$this->targetSchema]);
        $baseline = $catalog->inspect()->fingerprint();
        // Catalog identity includes the login role; compare two inspections using the same session.
        $reader = new PostgresCatalog($pdo, [$this->targetSchema]);
        $before = $reader->inspect()->fingerprint();

        foreach ([
            "UPDATE {$this->targetQuotedSchema}.sample_items SET name = 'Unauthorized change'",
            'ALTER TABLE '.$this->targetQuotedSchema.'.sample_items ADD COLUMN unauthorized text',
        ] as $sql) {
            $pdo->beginTransaction();

            try {
                expect(fn () => $pdo->exec($sql))->toThrow(PDOException::class);
            } finally {
                $pdo->rollBack();
            }
        }

        expect($reader->inspect()->fingerprint())->toBe($before)
            ->and($catalog->inspect()->fingerprint())->toBe($baseline)
            ->and($this->targetWriter->query('SELECT name FROM '.$this->targetQuotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Sample item A');
    });

    beforeEach(function (): void {
        $this->makeTargetPlan = function (PDO $pdo, array $connection): array {
            $catalog = new PostgresCatalog($pdo, [$this->targetSchema]);
            $this->targetWriter->exec('ALTER TABLE '.$this->targetQuotedSchema.'.sample_items ADD COLUMN note text');
            $desired = $catalog->inspect();
            $this->targetWriter->exec('ALTER TABLE '.$this->targetQuotedSchema.'.sample_items DROP COLUMN note');
            $actual = $catalog->inspect();
            $comparison = (new SchemaComparator)->compare($desired, $actual);
            $comparison['desired'] = $desired->toArray();
            $comparison['actual'] = $actual->toArray();
            $comparison['source'] = ['revision' => 'isolated-connection-tests'];
            $comparison['binding'] = TargetConnection::binding($connection, 'isolated-connection-tests', $this->app->environment());
            config()->set('database.connections.connection_tests', $connection);
            config()->set('schema-align.connection', 'connection_tests');
            config()->set('schema-align.target_instance', 'isolated-connection-tests');
            config()->set('schema-align.schemas', [$this->targetSchema]);
            config()->set('schema-align.plan_directory', $this->targetDirectory);

            return (new SchemaAlign($this->app))->pin($comparison, ['all-supported']);
        };
    });

    it('applies an approved matching plan with an admin session including SET ROLE', function (bool $switchRole): void {
        $pdo = TargetConnection::connect($this->adminConfig, 'isolated-connection-tests');
        $this->targetSessions[] = $pdo;

        if ($switchRole) {
            $pdo->exec('SET ROLE schema_align_test');
        }
        $plan = ($this->makeTargetPlan)($pdo, $this->adminConfig);
        $executor = new ApplyExecutor($pdo, new PlanStore($this->targetDirectory), [$this->targetSchema]);
        $executor->apply($plan['id'], $plan['signature'], $plan['binding']);
        $this->targetWriter->exec("UPDATE {$this->targetQuotedSchema}.sample_items SET note = 'Approved upgrade' WHERE id = 1");

        expect($this->targetWriter->query('SELECT note FROM '.$this->targetQuotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Approved upgrade')
            ->and($this->targetWriter->query('SELECT name FROM '.$this->targetQuotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Sample item A');
    })->with(['superuser login' => [false], 'restricted current role with superuser session' => [true]]);

    it('keeps approval and target safeguards effective for superuser apply', function (string $violation): void {
        $pdo = $this->adminPdo;
        $plan = ($this->makeTargetPlan)($pdo, $this->adminConfig);
        $approval = $plan['signature'];
        $binding = $plan['binding'];

        if ($violation === 'wrong approval') {
            $approval = str_repeat('0', 64);
        } elseif ($violation === 'tampered SQL') {
            $path = $this->targetDirectory.'/'.$plan['id'].'.plan.json';
            $edited = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $edited['operations'][0]['sql'] = ['DELETE FROM '.$this->targetQuotedSchema.'.sample_items'];
            file_put_contents($path, json_encode($edited, JSON_THROW_ON_ERROR));
        } elseif ($violation === 'another target binding') {
            $binding['instance'] = 'another-isolated-instance';
        } elseif ($violation === 'changed role identity') {
            $pdo->exec('SET ROLE schema_align_test');
        } elseif ($violation === 'stale catalog') {
            $this->targetWriter->exec('ALTER TABLE '.$this->targetQuotedSchema.'.sample_items ADD COLUMN external_change integer');
        }
        $catalog = new PostgresCatalog($this->targetWriter, [$this->targetSchema]);
        $before = $catalog->inspect()->fingerprint();
        $executor = new ApplyExecutor($pdo, new PlanStore($this->targetDirectory), [$this->targetSchema]);

        expect(fn () => $executor->apply($plan['id'], $approval, $binding))->toThrow(RuntimeException::class)
            ->and($catalog->inspect()->fingerprint())->toBe($before)
            ->and($this->targetWriter->query('SELECT name FROM '.$this->targetQuotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Sample item A');
    })->with(['wrong approval', 'tampered SQL', 'another target binding', 'changed role identity', 'stale catalog']);

    it('refuses a restricted-account plan after switching the configured login to superuser', function (): void {
        $plan = ($this->makeTargetPlan)($this->targetWriter, $this->targetConfig);
        $before = (new PostgresCatalog($this->targetWriter, [$this->targetSchema]))->inspect()->fingerprint();
        config()->set('database.connections.connection_tests', $this->adminConfig);

        expect(fn () => (new SchemaAlign($this->app))->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
            ->and((new PostgresCatalog($this->targetWriter, [$this->targetSchema]))->inspect()->fingerprint())->toBe($before)
            ->and($this->targetWriter->query('SELECT name FROM '.$this->targetQuotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Sample item A');
    });
});
