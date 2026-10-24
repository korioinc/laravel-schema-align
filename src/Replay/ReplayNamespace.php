<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use DateTimeImmutable;
use DateTimeZone;
use Korioinc\SchemaAlign\Database\TargetConnection;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Durable ownership and cooperative leases for trusted, bounded replay sessions. */
final class ReplayNamespace
{
    private readonly string $engine;

    private readonly string $digest;

    /** @var array<string, string> */
    private array $leases = [];

    /** @var array<string, array<string, mixed>> */
    private array $resources = [];

    /** @param array<string, mixed> $binding
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $schemas
     */
    public function __construct(private readonly PDO $pdo, private readonly array $binding, private readonly array $identity, private array $schemas)
    {
        $this->engine = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (! in_array($this->engine, ['pgsql', 'mysql'], true) || $pdo->inTransaction()) {
            throw new RuntimeException('Namespace management requires an independent writable PostgreSQL or MySQL session.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($this->engine === 'mysql' && (int) $this->rows('SELECT @@SESSION.autocommit AS enabled')[0]['enabled'] !== 1) {
            throw new RuntimeException('MySQL namespace markers require an independent autocommit session.');
        }
        sort($this->schemas, SORT_STRING);

        if ($this->schemas === [] || count(array_unique($this->schemas)) !== count($this->schemas)) {
            throw new RuntimeException('Namespace source scope must be nonempty and unique.');
        }

        if ($this->engine === 'mysql' && $this->schemas !== ['@database']) {
            throw new RuntimeException('MySQL namespace scope must be @database.');
        }
        foreach ($this->schemas as $schema) {
            if ($this->engine === 'pgsql' && ($schema === '' || preg_match('/[.\x00-\x1f]/', $schema) || strlen($schema) > 63 || str_starts_with($schema, 'pg_') || $schema === 'information_schema' || preg_match('/\Aalign-[0-9]{4}-[a-f0-9]{5}\z/D', $schema))) {
                throw new RuntimeException('Unsafe PostgreSQL source schema.');
            }
        }
        $this->digest = hash('sha256', $this->encode($this->canonical($binding)));
        $this->verifyIdentity($pdo);
    }

    /** @return array<string, mixed> */
    public function create(string $timezone = 'UTC'): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $run = bin2hex(random_bytes(16));
        $this->lock($run, 'coordinator');
        $resource = ['version' => 1, 'engine' => $this->engine, 'run_id' => $run,
            'created_at' => $now->format(DATE_ATOM), 'binding' => $this->digest, 'identity' => $this->identity,
            'schema_map' => [], 'namespaces' => [], 'namespace_oids' => [], 'state' => 'owned', 'sessions' => []];

        try {
            $this->verifyIdentity($this->pdo);

            if ($this->engine === 'mysql') {
                // Refuse predictable cleanup failure before committing CREATE DATABASE.
                $this->mysqlVisibility();
            }

            if ($this->engine === 'pgsql') {
                $this->noEventTriggers();
                foreach ($this->schemas as $schema) {
                    if ($this->rows('SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = ?', [$schema]) === []) {
                        throw new RuntimeException('Original PostgreSQL schema is absent.');
                    }
                }
                $this->pdo->beginTransaction();
            }
            foreach ($this->schemas as $schema) {
                $allocated = false;
                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $name = 'align-'.$now->format('md').'-'.$this->suffix($run, $schema, $attempt);

                    if ($this->engine === 'pgsql') {
                        $this->pdo->exec('SAVEPOINT namespace_allocate');
                    }

                    try {
                        $sql = $this->engine === 'pgsql' ? 'CREATE SCHEMA '.$this->quote($name) : $this->mysqlCreate($name);
                        $this->pdo->exec($sql);
                        $allocated = true;

                        break;
                    } catch (PDOException $e) {
                        if ($this->engine === 'pgsql') {
                            $this->pdo->exec('ROLLBACK TO SAVEPOINT namespace_allocate');
                        }

                        if (! ($this->engine === 'pgsql' ? $e->getCode() === '42P06' : (int) ($e->errorInfo[1] ?? 0) === 1007)) {
                            throw $e;
                        }
                    }
                }

                if (! $allocated) {
                    throw new RuntimeException('Namespace allocation exhausted collision attempts.');
                }
                $resource['schema_map'][$schema] = $name;
                $resource['namespaces'][] = $name;

                if ($this->engine === 'pgsql') {
                    $resource['namespace_oids'][$name] = (string) $this->rows('SELECT oid::text AS oid FROM pg_catalog.pg_namespace WHERE nspname=?', [$name])[0]['oid'];
                } else {
                    // CREATE DATABASE commits. A failure before this durable row leaves an unowned resource.
                    $this->resources[$run] = $resource;
                    // Satisfy primary-key policies without changing the single-payload marker contract.
                    // A 191-character utf8mb4 prefix fits even the 767-byte COMPACT/REDUNDANT key limit.
                    $this->pdo->exec('CREATE TABLE '.$this->quote($name).'.`__schema_align_run` (`payload` LONGTEXT NOT NULL, PRIMARY KEY (`payload`(191))) ENGINE=InnoDB');
                }
            }
            $this->write($resource, true);

            if ($this->engine === 'pgsql') {
                $this->pdo->commit();
            }
            $this->resources[$run] = $resource;

            return $resource;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->unlock($run, 'coordinator');

            $reason = $e instanceof RuntimeException && ! $e instanceof PDOException ? ' '.$e->getMessage() : '';

            throw new RuntimeException('Namespace create failed; run '.$run.'; ownership_unconfirmed resources: '.$this->encode($resource['namespaces']).'.'.$reason, 0, $e);
        }
    }

    /** @param array<string, mixed> $resource */
    public function workerLease(array $resource): void
    {
        $run = $this->run($resource['run_id'] ?? null);
        $coordinator = hash('sha256', 'schema-align:namespace:'.$this->digest.':'.$run.':coordinator');

        if ($this->engine === 'pgsql') {
            [$a, $b] = $this->pgKey($coordinator);
            $ownCoordinator = $this->rows('SELECT pid FROM pg_catalog.pg_locks WHERE locktype=\'advisory\' AND pid=pg_backend_pid() AND classid::bigint=? AND objid::bigint=? AND objsubid=2 AND granted', [$a < 0 ? $a + 4294967296 : $a, $b < 0 ? $b + 4294967296 : $b]) !== [];
        } else {
            $lock = $this->rows('SELECT IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS own', [$coordinator])[0];
            $ownCoordinator = $lock['owner'] === $lock['own'];
        }

        if ($ownCoordinator) {
            throw new RuntimeException('Worker lease requires a session independent of the coordinator.');
        }
        $this->lock($run, 'worker');

        try {
            $found = $this->discover($run);

            if ($found === null) {
                throw new RuntimeException('Worker ownership marker is absent.');
            }
            $this->validate($found, $run);

            if ($found['schema_map'] !== $resource['schema_map']) {
                throw new RuntimeException('Worker namespace mapping changed.');
            }
        } catch (Throwable $e) {
            $this->unlock($run, 'worker');

            throw $e;
        }
    }

    /** @param array<string, mixed> $resource */
    public function registerSession(array $resource, PDO $session): void
    {
        $run = $this->run($resource['run_id'] ?? null);

        if (! isset($this->leases['worker:'.$run])) {
            throw new RuntimeException('Acquire an independent worker lease before registering sessions.');
        }
        $this->assertHeld($run, 'worker');
        $current = $this->discover($run);

        if ($current === null) {
            throw new RuntimeException('Session registration requires durable ownership.');
        }
        $this->validate($current, $run);
        $this->verifyIdentity($session, $this->engine === 'mysql' ? $current['schema_map']['@database'] : null);
        $sql = $this->engine === 'pgsql'
            ? 'SELECT pid::text AS id, backend_start::text AS started FROM pg_catalog.pg_stat_activity WHERE pid=pg_backend_pid()'
            : 'SELECT CONNECTION_ID() AS id';
        $statement = $session->query($sql);
        $evidence = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($evidence) || ! isset($evidence['id']) || ($this->engine === 'pgsql' && ! isset($evidence['started']))) {
            throw new RuntimeException('Replay session visibility is insufficient.');
        }

        // Keep every live ownership check, but avoid rewriting unchanged durable evidence.
        if ($current['state'] === 'running' && ($current['sessions'][(string) $evidence['id']] ?? null) === $evidence) {
            return;
        }
        $current['sessions'][(string) $evidence['id']] = $evidence;
        $current['state'] = 'running';
        $this->write($current);
    }

    public function releaseWorker(): void
    {
        foreach (array_keys($this->leases) as $key) {
            if (str_starts_with($key, 'worker:')) {
                $this->unlock(substr($key, 7), 'worker');
            }
        }
    }

    /** @return array<string, mixed> */
    public function cleanup(string $run): array
    {
        $run = $this->run($run);

        if (isset($this->leases['worker:'.$run])) {
            throw new RuntimeException('cleanup_blocked: this session still owns the worker lease.');
        }
        $this->lock($run, 'coordinator');

        try {
            $this->lock($run, 'worker');
            $this->verifyIdentity($this->pdo);

            if ($this->engine === 'mysql') {
                $this->mysqlVisibility();
            }
            $resource = $this->discover($run);

            if ($resource === null) {
                $this->confirmAbsent($run);

                return ['status' => 'already_absent', 'run_id' => $run, 'namespaces' => []];
            }
            $this->validate($resource, $run);
            $this->sessionsEnded($resource);

            if ($this->engine === 'pgsql') {
                $this->noEventTriggers();
                $this->pdo->beginTransaction();
                $this->pdo->exec("SET LOCAL lock_timeout = '1000ms'; SET LOCAL statement_timeout = '30000ms'");
                // Re-read marker/OID after the transaction begins, before any object removal.
                $this->validate($resource, $run);
                $this->dropPostgres($resource);
                $this->pdo->commit();
            } else {
                $this->dropMySql($resource);
            }
            unset($this->resources[$run]);

            return ['status' => 'cleaned', 'run_id' => $run, 'namespaces' => $resource['namespaces']];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $reason = $e instanceof RuntimeException && ! $e instanceof PDOException ? $e->getMessage() : 'Database refused safe namespace removal; inspect the retained resource.';

            throw new RuntimeException('cleanup_blocked: run '.$run.'; namespaces '.$this->encode($resource['namespaces'] ?? $this->resources[$run]['namespaces'] ?? []).'; '.$reason, 0, $e);
        } finally {
            $this->unlock($run, 'worker');
            $this->unlock($run, 'coordinator');
        }
    }

    /** Discover related durable markers once; exact-run cleanup remains the deletion authority.
     * @return array<string, mixed>
     */
    public function cleanupOwned(): array
    {
        try {
            $this->verifyIdentity($this->pdo);

            if ($this->engine === 'mysql') {
                $this->mysqlVisibility();
            }
            $rows = $this->engine === 'pgsql'
                ? $this->rows("SELECT n.nspname AS name, pg_catalog.obj_description(n.oid, 'pg_namespace') AS payload FROM pg_catalog.pg_namespace n WHERE pg_catalog.obj_description(n.oid, 'pg_namespace') IS NOT NULL")
                : $this->mysqlMarkers();
        } catch (Throwable) {
            return ['status' => 'cleanup_blocked', 'runs' => [], 'errors' => ['Owned namespace discovery is unconfirmed; no resources were removed.']];
        }
        $candidates = [];
        $errors = [];
        foreach ($rows as $row) {
            // Ordinary schema comments and unrelated marker tables confer no authority.
            $marker = json_decode((string) $row['payload'], true, 512);

            if (! is_array($marker) || ($marker['binding'] ?? null) !== $this->digest) {
                continue;
            }

            if (is_array($marker['identity'] ?? null) && $this->canonical($marker['identity']) !== $this->canonical($this->identity)) {
                continue;
            }
            $scope = is_array($marker['schema_map'] ?? null) ? array_map(strval(...), array_keys($marker['schema_map'])) : null;

            if ($scope !== null) {
                sort($scope, SORT_STRING);

                if ($scope !== $this->schemas) {
                    continue;
                }
            }
            $run = $marker['run_id'] ?? null;
            $validRun = is_string($run) && preg_match('/\A[a-f0-9]{32}\z/D', $run) === 1;
            $key = $validRun ? $run : 'invalid:'.hash('sha256', (string) $row['name']);

            if (! isset($candidates[$key]) && count($candidates) >= 100) {
                $errors = ['Owned cleanup discovery exceeded 100 candidates; additional runs were retained.'];

                continue;
            }
            $candidate = $candidates[$key] ?? ['run_id' => $validRun ? $run : null, 'namespaces' => [], 'errors' => []];

            // Only observed marker locations enter diagnostics; payload names are untrusted.
            if (! in_array($row['name'], $candidate['namespaces'], true)) {
                $candidate['namespaces'][] = $row['name'];
            }

            if (! $validRun || ($marker['version'] ?? null) !== 1 || ($marker['engine'] ?? null) !== $this->engine
                || ! is_array($marker['identity'] ?? null) || $scope === null
                || ! is_array($marker['namespaces'] ?? null) || ! in_array($row['name'], $marker['namespaces'], true)) {
                $candidate['errors'] = ['Related ownership marker is malformed or unsupported; resource retained.'];
            }
            $candidates[$key] = $candidate;
        }
        $runs = [];
        $blocked = $errors !== [];
        foreach ($candidates as $candidate) {
            if ($candidate['errors'] !== []) {
                $runs[] = ['status' => 'cleanup_blocked', ...$candidate];
                $blocked = true;

                continue;
            }

            try {
                $runs[] = $this->cleanup($candidate['run_id']);
            } catch (Throwable $error) {
                $runs[] = ['status' => 'cleanup_blocked', 'run_id' => $candidate['run_id'],
                    'namespaces' => $candidate['namespaces'],
                    'errors' => [$error instanceof RuntimeException && ! $error instanceof PDOException
                        ? $error->getMessage() : 'Database refused safe namespace removal; resource retained.']];
                $blocked = true;
            }
        }
        $result = ['status' => $blocked ? 'cleanup_blocked' : ($runs === [] ? 'already_absent' : 'cleaned'), 'runs' => $runs];

        if ($errors !== []) {
            $result['errors'] = $errors;
        }

        return $result;
    }

    public function release(): void
    {
        foreach (array_keys($this->leases) as $key) {
            if (str_starts_with($key, 'coordinator:')) {
                $this->unlock(substr($key, 12), 'coordinator');
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function discover(string $run): ?array
    {
        $found = null;
        $candidates = $this->engine === 'pgsql'
            ? $this->rows("SELECT n.nspname AS name, pg_catalog.obj_description(n.oid, 'pg_namespace') AS payload FROM pg_catalog.pg_namespace n WHERE pg_catalog.obj_description(n.oid, 'pg_namespace') IS NOT NULL")
            : $this->mysqlMarkers();
        foreach ($candidates as $row) {
            $payload = (string) $row['payload'];

            if (! str_contains($payload, $run)) {
                continue;
            }
            $marker = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($marker) || ($marker['run_id'] ?? null) !== $run) {
                throw new RuntimeException('Malformed matching namespace marker.');
            }

            if (! in_array($row['name'], $marker['namespaces'] ?? [], true) || ($found !== null && $found !== $marker)) {
                throw new RuntimeException('Conflicting namespace ownership markers.');
            }
            $found = $marker;
        }

        return $found;
    }

    /** @param array<string, mixed> $resource */
    private function validate(array $resource, string $run): void
    {
        if (($resource['version'] ?? null) !== 1 || ($resource['engine'] ?? null) !== $this->engine || ($resource['run_id'] ?? null) !== $run
            || ($resource['binding'] ?? null) !== $this->digest || ! is_array($resource['identity'] ?? null)
            || $this->canonical($resource['identity']) !== $this->canonical($this->identity)
            || ! is_array($resource['schema_map'] ?? null) || array_map(strval(...), array_keys($resource['schema_map'])) !== $this->schemas
            || ! is_array($resource['namespaces'] ?? null) || array_values($resource['schema_map']) !== $resource['namespaces']
            || count(array_unique($resource['namespaces'])) !== count($this->schemas)
            || ! is_string($resource['created_at'] ?? null) || $resource['created_at'] === ''
            || ! is_array($resource['sessions'] ?? null) || ! in_array($resource['state'] ?? null, ['owned', 'running'], true)) {
            throw new RuntimeException('Namespace ownership, identity, binding or scope is invalid.');
        }
        foreach ($resource['namespaces'] as $name) {
            if (! is_string($name) || ! preg_match('/\Aalign-[0-9]{4}-[a-f0-9]{5}\z/D', $name)
                || in_array($name, $this->schemas, true) || $name === ($this->binding['database'] ?? null)) {
                throw new RuntimeException('Namespace overlaps original scope or has an invalid name.');
            }
            $marker = $this->readMarker($name);

            if ($marker !== $resource) {
                throw new RuntimeException('Namespace marker changed or is absent.');
            }

            if ($this->engine === 'pgsql') {
                $rows = $this->rows('SELECT oid::text AS oid FROM pg_catalog.pg_namespace WHERE nspname=?', [$name]);

                if ($rows === [] || $rows[0]['oid'] !== ($resource['namespace_oids'][$name] ?? null)) {
                    throw new RuntimeException('Namespace OID changed.');
                }
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readMarker(string $name): ?array
    {
        $rows = $this->engine === 'pgsql'
            ? $this->rows("SELECT pg_catalog.obj_description(oid, 'pg_namespace') AS payload FROM pg_catalog.pg_namespace WHERE nspname=?", [$name])
            : $this->rows('SELECT payload FROM '.$this->quote($name).'.`__schema_align_run`');

        if (count($rows) !== 1 || ! is_string($rows[0]['payload'])) {
            return null;
        }
        $marker = json_decode($rows[0]['payload'], true, 512, JSON_THROW_ON_ERROR);

        return is_array($marker) ? $marker : null;
    }

    /** @param array<string, mixed> $resource */
    private function write(array $resource, bool $insert = false): void
    {
        $payload = $this->encode($resource);
        foreach ($resource['namespaces'] as $name) {
            if ($this->engine === 'pgsql') {
                $this->pdo->exec('COMMENT ON SCHEMA '.$this->quote($name).' IS '.$this->pdo->quote($payload));
            } else {
                $table = $this->quote($name).'.`__schema_align_run`';
                $statement = $this->pdo->prepare($insert ? 'INSERT INTO '.$table.' (payload) VALUES (?)' : 'UPDATE '.$table.' SET payload=?');
                $statement->execute([$payload]);
            }
        }
    }

    /** @param array<string, mixed> $resource */
    private function sessionsEnded(array $resource): void
    {
        if ($this->engine === 'mysql') {
            $this->mysqlVisibility();
        }
        foreach ($resource['sessions'] as $evidence) {
            if (! is_array($evidence) || ! isset($evidence['id']) || ! ctype_digit((string) $evidence['id'])) {
                throw new RuntimeException('Invalid session evidence.');
            }
            $sql = $this->engine === 'pgsql' ? 'SELECT pid FROM pg_catalog.pg_stat_activity WHERE pid=?' : 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID=?';

            // Any surviving or reused ID is unsafe, including an idle connection.
            if ($this->rows($sql, [$evidence['id']]) !== []) {
                throw new RuntimeException('A registered replay session is still present or its ID was reused.');
            }
        }
    }

    /** @param array<string, mixed> $resource */
    private function dropPostgres(array $resource): void
    {
        $tables = [];
        $sequences = [];
        foreach ($resource['namespaces'] as $name) {
            foreach ($this->rows('SELECT c.relname, c.relkind, c.relispartition FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? ORDER BY c.relname', [$name]) as $row) {
                $qualified = $this->quote($name).'.'.$this->quote($row['relname']);

                if ($row['relkind'] === 'p' || in_array($row['relispartition'], [true, 1, '1', 't'], true)) {
                    throw new RuntimeException('Partitioned objects prevent safe namespace cleanup.');
                }

                if ($row['relkind'] === 'r') {
                    $tables[] = $qualified;
                } elseif ($row['relkind'] === 'S') {
                    $sequences[] = [$name, $row['relname']];
                } elseif (! in_array($row['relkind'], ['i', 'I', 't'], true)) {
                    throw new RuntimeException('Unsupported owned object prevents safe namespace cleanup.');
                }
            }
        }

        // RESTRICT still follows automatic dependencies, including owned sequences.
        foreach ($resource['namespaces'] as $name) {
            $outside = $this->rows("SELECT DISTINCT dn.nspname FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_class dependent ON d.classid='pg_catalog.pg_class'::regclass AND dependent.oid=d.objid JOIN pg_catalog.pg_namespace dn ON dn.oid=dependent.relnamespace JOIN pg_catalog.pg_class referenced ON d.refclassid='pg_catalog.pg_class'::regclass AND referenced.oid=d.refobjid JOIN pg_catalog.pg_namespace rn ON rn.oid=referenced.relnamespace WHERE rn.nspname=?", [$name]);
            foreach ($outside as $dependency) {
                if ($dependency['nspname'] !== 'pg_toast' && ! in_array($dependency['nspname'], $resource['namespaces'], true)) {
                    throw new RuntimeException('External automatic dependencies prevent namespace cleanup.');
                }
            }
        }

        if ($tables !== []) {
            $this->pdo->exec('DROP TABLE '.implode(', ', $tables).' RESTRICT');
        }
        foreach ($sequences as [$schema, $name]) {
            if ($this->rows('SELECT c.oid FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? AND c.relname=?', [$schema, $name]) !== []) {
                $this->pdo->exec('DROP SEQUENCE '.$this->quote($schema).'.'.$this->quote($name).' RESTRICT');
            }
        }
        foreach ($resource['namespaces'] as $name) {
            $this->pdo->exec('DROP SCHEMA '.$this->quote($name).' RESTRICT');
        }
    }

    /** @param array<string, mixed> $resource */
    private function dropMySql(array $resource): void
    {
        $this->mysqlVisibility();
        $this->pdo->exec('SET SESSION lock_wait_timeout = 5, innodb_lock_wait_timeout = 5');

        if ((int) $this->rows('SELECT @@SESSION.foreign_key_checks AS enabled')[0]['enabled'] !== 1) {
            throw new RuntimeException('MySQL foreign_key_checks must remain enabled.');
        }
        foreach ($resource['namespaces'] as $name) {
            $dependencies = $this->rows('SELECT TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=? AND TABLE_SCHEMA<>?', [$name, $name]);
            $views = $this->rows('SELECT VIEW_SCHEMA FROM information_schema.VIEW_TABLE_USAGE WHERE TABLE_SCHEMA=? AND VIEW_SCHEMA<>?', [$name, $name]);

            if ($dependencies !== [] || $views !== []) {
                throw new RuntimeException('External MySQL dependencies prevent namespace cleanup.');
            }
            $this->validate($resource, $resource['run_id']);
            $this->pdo->exec('DROP DATABASE '.$this->quote($name));

            if ($this->exists($name)) {
                throw new RuntimeException('MySQL namespace removal is unconfirmed.');
            }
        }
    }

    private function noEventTriggers(): void
    {
        if ($this->rows("SELECT oid FROM pg_catalog.pg_event_trigger WHERE evtenabled <> 'D'") !== []) {
            throw new RuntimeException('Enabled PostgreSQL event triggers prevent namespace DDL.');
        }
    }

    private function mysqlVisibility(): void
    {
        $global = TargetConnection::mysqlGlobalPrivileges($this->pdo);
        $required = ['PROCESS', 'SHOW DATABASES', 'SELECT', 'SHOW VIEW'];

        if (! in_array('ALL PRIVILEGES', $global, true) && array_diff($required, $global) !== []) {
            throw new RuntimeException('MySQL replay cleanup requires global PROCESS, SHOW DATABASES, SELECT and SHOW VIEW visibility; metadata uncertainty refuses namespace creation and deletion.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function mysqlMarkers(): array
    {
        // Discovery is read-only; deletion additionally demands complete metadata visibility.
        $result = [];
        foreach ($this->rows("SELECT TABLE_SCHEMA AS name FROM information_schema.TABLES WHERE TABLE_NAME='__schema_align_run' AND TABLE_TYPE='BASE TABLE'") as $row) {
            $name = (string) $row['name'];

            if (! preg_match('/\Aalign-[0-9]{4}-[a-f0-9]{5}\z/D', $name)) {
                continue;
            }
            $columns = $this->rows("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='__schema_align_run'", [$name]);

            // Unrelated tables confer no ownership. Exact-run absence checks still retain unconfirmed candidates.
            if (count($columns) !== 1 || $columns[0]['COLUMN_NAME'] !== 'payload' || $columns[0]['DATA_TYPE'] !== 'longtext'
                || $columns[0]['IS_NULLABLE'] !== 'NO' || $columns[0]['GENERATION_EXPRESSION'] !== '') {
                continue;
            }
            foreach ($this->rows('SELECT payload FROM '.$this->quote($name).'.`__schema_align_run`') as $marker) {
                $result[] = ['name' => $name, 'payload' => $marker['payload']];
            }
        }

        return $result;
    }

    private function mysqlCreate(string $name): string
    {
        $row = $this->rows('SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$this->binding['database'] ?? '']);

        if (count($row) !== 1 || ! preg_match('/\A[a-zA-Z0-9_]+\z/D', $row[0]['charset']) || ! preg_match('/\A[a-zA-Z0-9_]+\z/D', $row[0]['collation'])) {
            throw new RuntimeException('Cannot verify original MySQL charset and collation.');
        }

        return 'CREATE DATABASE '.$this->quote($name).' CHARACTER SET '.$row[0]['charset'].' COLLATE '.$row[0]['collation'];
    }

    private function verifyIdentity(PDO $session, ?string $database = null): void
    {
        if ($session->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->engine) {
            throw new RuntimeException('Namespace connection engine changed.');
        }
        $sql = $this->engine === 'pgsql'
            ? 'SELECT current_database() AS database, current_user AS current_user, inet_server_addr()::text AS server_addr, inet_server_port() AS server_port, oid::text AS database_oid, version() AS version FROM pg_catalog.pg_database WHERE datname=current_database()'
            : 'SELECT @@server_uuid AS server_uuid, DATABASE() AS `database`, CURRENT_USER() AS `current_user`, USER() AS session_user, @@hostname AS host, @@port AS port, VERSION() AS version, @@version_comment AS product';
        $query = $session->query($sql);
        $actual = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);
        $required = $this->engine === 'pgsql' ? ['database', 'database_oid'] : ['database', 'server_uuid'];

        if (! is_array($actual)) {
            throw new RuntimeException('Cannot verify namespace server identity.');
        }
        foreach ($required as $key) {
            if (! isset($this->identity[$key])) {
                throw new RuntimeException('Missing namespace server identity evidence.');
            }
        }
        foreach ($this->identity as $key => $value) {
            if ($key === 'database' && $database !== null) {
                $value = $database;
            }

            if (! array_key_exists($key, $actual) || (string) $actual[$key] !== (string) $value) {
                throw new RuntimeException('Namespace server identity mismatch.');
            }
        }
    }

    private function suffix(string $run, string $schema, int $attempt): string
    {
        return substr(hash('sha256', $run.'|'.$this->encode($this->schemas).'|'.$schema.'|'.$attempt), 0, 5);
    }

    private function confirmAbsent(string $run): void
    {
        $suffixes = [];
        foreach ($this->schemas as $schema) {
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $suffixes[] = $this->suffix($run, $schema, $attempt);
            }
        }
        $rows = $this->rows($this->engine === 'pgsql'
            ? 'SELECT nspname AS name FROM pg_catalog.pg_namespace'
            : 'SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA');
        foreach ($rows as $row) {
            // Derivation identifies possible absence checks, never authority to adopt or delete.
            if (preg_match('/\Aalign-[0-9]{4}-([a-f0-9]{5})\z/D', $row['name'], $match)
                && in_array($match[1], $suffixes, true)) {
                throw new RuntimeException('Ownership is unconfirmed for a possible namespace of this run.');
            }
        }
        foreach ($this->resources[$run]['namespaces'] ?? [] as $name) {
            if ($this->exists($name)) {
                throw new RuntimeException('Ownership is unconfirmed for an existing namespace.');
            }
        }
    }

    private function exists(string $name): bool
    {
        return $this->rows($this->engine === 'pgsql' ? 'SELECT oid FROM pg_catalog.pg_namespace WHERE nspname=?' : 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$name]) !== [];
    }

    private function lock(string $run, string $role): void
    {
        $index = $role.':'.$run;

        if (isset($this->leases[$index])) {
            $this->assertHeld($run, $role);

            return;
        }
        $key = hash('sha256', 'schema-align:namespace:'.$this->digest.':'.$run.':'.$role);

        if ($this->engine === 'pgsql') {
            [$a, $b] = $this->pgKey($key);
            $ok = $this->rows('SELECT pg_catalog.pg_try_advisory_lock(?, ?) AS acquired', [$a, $b])[0]['acquired'];
        } else {
            $ok = $this->rows('SELECT GET_LOCK(?, 0) AS acquired', [$key])[0]['acquired'];
        }

        if (! in_array($ok, [true, 1, '1', 't'], true)) {
            throw new RuntimeException('Namespace '.$role.' lease is active or unavailable.');
        }
        $this->leases[$index] = $key;
    }

    private function assertHeld(string $run, string $role): void
    {
        $key = $this->leases[$role.':'.$run];

        if ($this->engine === 'pgsql') {
            [$a, $b] = $this->pgKey($key);
            $rows = $this->rows('SELECT pid FROM pg_catalog.pg_locks WHERE locktype=\'advisory\' AND pid=pg_backend_pid() AND classid::bigint=? AND objid::bigint=? AND objsubid=2 AND granted', [$a < 0 ? $a + 4294967296 : $a, $b < 0 ? $b + 4294967296 : $b]);
        } else {
            $rows = $this->rows('SELECT IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS own', [$key]);

            if ($rows[0]['owner'] !== $rows[0]['own']) {
                $rows = [];
            }
        }

        if ($rows === []) {
            throw new RuntimeException('Namespace lease was lost; further work is refused.');
        }
    }

    private function unlock(string $run, string $role): void
    {
        $index = $role.':'.$run;

        if (! isset($this->leases[$index])) {
            return;
        }
        $key = $this->leases[$index];

        if ($this->engine === 'pgsql') {
            [$a, $b] = $this->pgKey($key);
            $this->rows('SELECT pg_catalog.pg_advisory_unlock(?, ?)', [$a, $b]);
        } else {
            $this->rows('SELECT RELEASE_LOCK(?)', [$key]);
        }
        unset($this->leases[$index]);
    }

    /** @return array{int, int} */
    private function pgKey(string $key): array
    {
        $a = (int) hexdec(substr($key, 0, 8));
        $b = (int) hexdec(substr($key, 8, 8));

        return [$a > 2147483647 ? $a - 4294967296 : $a, $b > 2147483647 ? $b - 4294967296 : $b];
    }

    private function run(mixed $run): string
    {
        if (! is_string($run) || ! preg_match('/\A[a-f0-9]{32}\z/D', $run)) {
            throw new RuntimeException('An exact 32-character lowercase hexadecimal run ID is required.');
        }

        return $run;
    }

    private function quote(string $identifier): string
    {
        $quote = $this->engine === 'mysql' ? '`' : '"';

        return $quote.str_replace($quote, $quote.$quote, $identifier).$quote;
    }

    /** @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters = []): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($parameters);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($this->canonical(...), $value);
        }

        return $value;
    }
}
