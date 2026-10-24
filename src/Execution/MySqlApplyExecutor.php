<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Execution;

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Planning\PlanStore;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Snapshot;
use PDO;
use RuntimeException;
use Throwable;

final class MySqlApplyExecutor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PlanStore $store,
        private readonly string $migrationTable = 'migrations',
    ) {}

    /**
     * @param  array<string, mixed>  $binding
     * @return array{plan: array<string, mixed>, status: string, fingerprint: string, completed_operations: int}
     */
    public function review(string $id, string $expectedSignature, array $binding, bool $recover = false, int $lockTimeout = 5000): array
    {
        $plan = $this->validatedPlan($id, $expectedSignature, $binding);
        $checkpoints = $this->checkpoints($plan);
        $lockName = $this->acquireDatabaseLock($lockTimeout);
        $settings = null;

        try {
            $settings = $this->sessionTimeouts();
            $this->configureTimeouts($lockTimeout);
            $classification = $this->classify($id, $plan, $checkpoints, $recover);

            return ['plan' => $plan, ...$classification];
        } finally {
            try {
                if ($settings !== null) {
                    $this->restoreTimeouts($settings);
                }
            } finally {
                $this->releaseDatabaseLock($lockName);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    public function apply(string $id, string $approval, array $binding, bool $recover = false, int $lockTimeout = 5000, int $statementTimeout = 0): array
    {
        $fileLock = $this->store->lock($id);
        $locked = false;
        $started = false;
        $settings = null;
        $lockName = '';
        $plan = [];
        $checkpoints = [];
        $warnings = $statementTimeout > 0 ? ['MySQL DDL has no statement execution timeout; only lock waits are bounded.'] : [];

        try {
            $plan = $this->validatedPlan($id, $approval, $binding);
            $checkpoints = $this->checkpoints($plan);
            $lockName = $this->acquireDatabaseLock($lockTimeout);
            $locked = true;
            $settings = $this->sessionTimeouts();
            $this->configureTimeouts($lockTimeout);
            $classification = $this->classify($id, $plan, $checkpoints, $recover);
            $fingerprint = $classification['fingerprint'];
            $completed = $classification['completed_operations'];

            if ($classification['status'] === 'already_applied') {
                $state = $this->store->status($id);
                $result = ['status' => 'applied', 'warnings' => $warnings, 'reconciled' => ($state['status'] ?? '') !== 'applied', 'completed_operations' => $completed, 'fingerprint' => $fingerprint];
                $this->store->record($id, $result);

                return $result;
            }
            // MySQL max_execution_time does not bound DDL. Never advertise a DDL timeout.
            $this->execute('SET SESSION transaction_read_only = OFF');
            $started = true;
            foreach ($plan['operations'] as $index => $operation) {
                if ($index < $completed) {
                    continue;
                }
                $before = $this->inspect();
                $this->verifyCatalog($before, $plan);

                if ($before->fingerprint() !== $operation['before_hash']) {
                    throw new RuntimeException('Schema changed before DDL. Issue a new reviewed plan.');
                }
                $this->store->record($id, ['status' => 'executing', 'operation_index' => $index, 'completed_operations' => $completed, 'before_hash' => $operation['before_hash'], 'after_hash' => $operation['after_hash']]);
                $this->execute($operation['sql'][0]);
                $after = $this->inspect();
                $this->verifyCatalog($after, $plan);
                $afterHash = $after->fingerprint();

                if ($afterHash !== $operation['after_hash']) {
                    throw new RuntimeException('MySQL DDL postcondition mismatch. Committed changes cannot be rolled back.');
                }
                $completed = $index + 1;
                $this->store->record($id, ['status' => 'partial', 'completed_operations' => $completed, 'fingerprint' => $afterHash]);
            }
            $result = ['status' => 'applied', 'warnings' => $warnings, 'reconciled' => false, 'recovered' => $recover, 'completed_operations' => $completed, 'fingerprint' => $plan['after_hash']];
            $this->store->record($id, $result);

            return $result;
        } catch (Throwable $error) {
            if ($started) {
                try {
                    $observed = $this->inspect();
                    $this->verifyCatalog($observed, $plan);
                    $fingerprint = $observed->fingerprint();
                    $matches = array_keys($checkpoints, $fingerprint, true);
                    $completed = count($matches) === 1 ? $matches[0] : null;
                    $status = $completed === null ? 'unknown' : ($completed === 0 ? 'failed' : ($completed === count($plan['operations']) ? 'applied' : 'partial'));
                    $this->store->record($id, ['status' => $status, 'completed_operations' => $completed, 'fingerprint' => $fingerprint, 'error' => $error->getMessage()]);
                } catch (Throwable) {
                    $this->store->record($id, ['status' => 'unknown', 'error' => 'Connection or state inspection failed. Reconnect and explicitly recover.']);
                }
            }

            throw $error;
        } finally {
            $cleanupError = null;

            if ($settings !== null) {
                try {
                    if ($started) {
                        $this->execute('SET SESSION transaction_read_only = ON');
                    }
                    $this->restoreTimeouts($settings);
                } catch (Throwable $error) {
                    $cleanupError = $error;
                }
            }

            if ($locked) {
                try {
                    $this->releaseDatabaseLock($lockName);
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
            flock($fileLock, LOCK_UN);
            fclose($fileLock);

            if ($cleanupError !== null) {
                throw new RuntimeException('Cannot restore MySQL session safety settings or release its lock. Discard this connection and explicitly recover; committed checkpoints remain recorded.', 0, $cleanupError);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    private function validatedPlan(string $id, string $expectedSignature, array $binding): array
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('MySQL apply requires a MySQL connection.');
        }
        TargetConnection::requireRestrictedRole($this->pdo);

        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('MySQL DDL cannot run inside an existing transaction.');
        }
        $plan = $this->store->load($id);

        if (! hash_equals((string) $plan['signature'], $expectedSignature)) {
            throw new RuntimeException('Approval must equal the reviewed plan signature.');
        }

        if (($plan['engine'] ?? $plan['binding']['driver'] ?? null) !== 'mysql'
            || (isset($plan['binding']['driver']) && $plan['binding']['driver'] !== 'mysql')
            || ($plan['execution_mode'] ?? null) !== 'checkpointed') {
            throw new RuntimeException('Plan must explicitly select MySQL checkpointed execution.');
        }

        if (Snapshot::canonical($binding) !== Snapshot::canonical($plan['binding'])
            || ($plan['schemas'] ?? null) !== ['@database']
            || ($plan['migration_table'] ?? null) !== $this->migrationTable) {
            throw new RuntimeException('Plan is bound to another target, environment, or schema scope.');
        }

        return $plan;
    }

    /** @return array{0: mixed, 1: mixed} */
    private function sessionTimeouts(): array
    {
        $query = $this->pdo->query('SELECT @@SESSION.lock_wait_timeout, @@SESSION.innodb_lock_wait_timeout');
        $row = $query === false ? false : $query->fetch(PDO::FETCH_NUM);

        if (! is_array($row) || count($row) !== 2) {
            throw new RuntimeException('Cannot inspect MySQL session lock timeouts.');
        }

        return [$row[0], $row[1]];
    }

    private function configureTimeouts(int $lockTimeout): void
    {
        $seconds = min(1073741824, max(1, (int) ceil(max(0, $lockTimeout) / 1000)));
        $this->execute('SET SESSION lock_wait_timeout = '.$seconds);
        $this->execute('SET SESSION innodb_lock_wait_timeout = '.$seconds);
    }

    /** @param array{0: mixed, 1: mixed} $settings */
    private function restoreTimeouts(array $settings): void
    {
        $this->execute('SET SESSION lock_wait_timeout = '.(int) $settings[0]);
        $this->execute('SET SESSION innodb_lock_wait_timeout = '.(int) $settings[1]);
    }

    private function acquireDatabaseLock(int $lockTimeout): string
    {
        $database = $this->pdo->query('SELECT DATABASE()');

        if ($database === false || ! is_string($name = $database->fetchColumn()) || $name === '') {
            throw new RuntimeException('Cannot resolve MySQL database apply lock.');
        }
        $lockName = 'schema-align:'.substr(hash('sha256', $name), 0, 50);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');

        if ($lock === false || ! $lock->execute([$lockName, min(31536000, (int) ceil(max(0, $lockTimeout) / 1000))]) || (string) $lock->fetchColumn() !== '1') {
            throw new RuntimeException('Another schema-align apply holds the database lock, or lock acquisition failed.');
        }

        return $lockName;
    }

    private function releaseDatabaseLock(string $lockName): void
    {
        $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');

        if ($release === false || ! $release->execute([$lockName]) || (string) $release->fetchColumn() !== '1') {
            throw new RuntimeException('Cannot release MySQL database apply lock.');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $checkpoints
     * @return array{status: string, fingerprint: string, completed_operations: int}
     */
    private function classify(string $id, array $plan, array $checkpoints, bool $recover): array
    {
        $actual = $this->inspect();
        $this->verifyCatalog($actual, $plan);
        $fingerprint = $actual->fingerprint();
        $completed = $this->completed($fingerprint, $checkpoints);
        $complete = $completed === count($plan['operations']);

        if (! $complete && ! $recover) {
            $state = $this->store->status($id);

            if (($state['status'] ?? '') !== 'planned' || $completed !== 0) {
                throw new RuntimeException('An earlier attempt exists. Review execution status and pass --recover to resume a verified checkpoint.');
            }
        }

        return ['status' => $complete ? 'already_applied' : 'pending', 'fingerprint' => $fingerprint, 'completed_operations' => $completed];
    }

    /** @param array<string, mixed> $plan
     * @return list<string>
     */
    private function checkpoints(array $plan): array
    {
        if (! is_array($plan['operations'] ?? null) || ! array_is_list($plan['operations'])) {
            throw new RuntimeException('Invalid MySQL operations.');
        }
        $hashes = [$plan['before_hash'] ?? null];
        foreach ($plan['operations'] as $operation) {
            if (($operation['automatic'] ?? null) !== true || ($operation['transactional'] ?? null) !== false
                || ! is_array($operation['sql'] ?? null) || ! array_is_list($operation['sql']) || count($operation['sql']) !== 1
                || ! is_string($operation['sql'][0]) || trim($operation['sql'][0]) === ''
                || ($operation['before_hash'] ?? null) !== $hashes[array_key_last($hashes)]) {
                throw new RuntimeException('Each automatic MySQL operation requires one nontransactional SQL statement and continuous signed checkpoints.');
            }
            $hashes[] = $operation['after_hash'] ?? null;
        }
        foreach ($hashes as $hash) {
            if (! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                throw new RuntimeException('Invalid MySQL checkpoint hash.');
            }
        }

        if (end($hashes) !== ($plan['after_hash'] ?? null) || count(array_unique($hashes)) !== count($hashes)) {
            throw new RuntimeException('Inconsistent or ambiguous MySQL checkpoints. Issue a new reviewed plan.');
        }

        return $hashes;
    }

    /** @param list<string> $checkpoints */
    private function completed(string $fingerprint, array $checkpoints): int
    {
        $matches = array_keys($checkpoints, $fingerprint, true);

        if (count($matches) !== 1) {
            throw new RuntimeException('Stale or divergent MySQL plan. Issue a new reviewed plan; no SQL rerun.');
        }

        return $matches[0];
    }

    /** @param array<string, mixed> $plan */
    private function verifyCatalog(Snapshot $actual, array $plan): void
    {
        if (Snapshot::canonical($actual->identity) !== Snapshot::canonical($plan['identity'])) {
            throw new RuntimeException('Resolved database identity differs from the approved plan.');
        }

        if (($actual->platform['engine'] ?? null) !== 'mysql' || $actual->unsupported !== []) {
            throw new RuntimeException('Incomplete or unsupported MySQL catalog coverage; apply refused.');
        }
    }

    private function inspect(): Snapshot
    {
        return (new MySqlCatalog($this->pdo, $this->migrationTable))->inspect();
    }

    private function execute(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new RuntimeException('MySQL statement failed: '.($this->pdo->errorInfo()[2] ?? 'unknown error'));
        }
    }
}
