<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Execution;

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Planning\PlanStore;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Snapshot;
use PDO;
use RuntimeException;
use Throwable;

final class ApplyExecutor
{
    /** @param list<string> $schemas */
    public function __construct(
        private readonly PDO $pdo,
        private readonly PlanStore $store,
        private readonly array $schemas = ['public'],
        private readonly string $migrationTable = 'migrations',
    ) {}

    /**
     * @param  array<string, mixed>  $binding
     * @return array{plan: array<string, mixed>, status: string, fingerprint: string, completed_operations: int}
     */
    public function review(string $id, string $expectedSignature, array $binding, bool $recover = false, int $lockTimeout = 5000): array
    {
        $plan = $this->validatedPlan($id, $expectedSignature, $binding);
        $this->acquireDatabaseLock();
        $settings = null;

        try {
            $settings = $this->sessionTimeouts();
            $this->configureTimeouts($lockTimeout, 0);
            $classification = $this->classify($id, $plan, $recover);
            $this->requireApplicable($classification);

            return [
                'plan' => $plan,
                'status' => $classification['status'],
                'fingerprint' => $classification['fingerprint'],
                'completed_operations' => $classification['completed_operations'],
            ];
        } finally {
            try {
                if ($settings !== null) {
                    $this->restoreTimeouts($settings);
                }
            } finally {
                $this->releaseDatabaseLock();
            }
        }
    }

    /** @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    public function apply(string $id, string $approval, array $binding, bool $recover = false, int $lockTimeout = 5000, int $statementTimeout = 0): array
    {
        if ($statementTimeout < 0 || $statementTimeout > 2147483647) {
            throw new RuntimeException('PostgreSQL statement timeout must be between 0 and 2147483647 milliseconds.');
        }
        $fileLock = $this->store->lock($id);
        $locked = false;
        $started = false;
        $settings = null;
        $plan = [];

        try {
            $plan = $this->validatedPlan($id, $approval, $binding);
            $this->acquireDatabaseLock();
            $locked = true;
            $settings = $this->sessionTimeouts();
            $this->configureTimeouts($lockTimeout, $statementTimeout);
            $classification = $this->classify($id, $plan, $recover);
            $actual = $classification['actual'];

            if ($classification['status'] === 'already_applied') {
                $state = $this->store->status($id);
                $result = ['status' => 'applied', 'reconciled' => ($state['status'] ?? '') !== 'applied', 'fingerprint' => $classification['fingerprint']];
                $this->store->record($id, $result);

                return $result;
            }

            if ($classification['status'] === 'divergent') {
                $this->store->record($id, ['status' => 'divergent', 'fingerprint' => $classification['fingerprint']]);
            }
            $this->requireApplicable($classification);
            $started = true;
            $this->store->record($id, ['status' => 'executing', 'before_hash' => $actual->fingerprint()]);
            $this->pdo->exec('SET default_transaction_read_only = off');
            $this->pdo->beginTransaction();

            if ($actual->platform['server_version_num'] >= 170000) {
                $this->pdo->exec('SET LOCAL transaction_timeout = 0');
            }
            $this->pdo->exec('SET LOCAL lock_timeout = '.$this->pdo->quote((string) max(1, $lockTimeout).'ms'));
            $this->pdo->exec('SET LOCAL statement_timeout = '.$this->pdo->quote($statementTimeout.'ms'));
            $this->pdo->exec('SET LOCAL search_path = pg_catalog, public');
            // Advisory locks serialize this package; table locks also fence concurrent external table DDL.
            $tables = $actual->tables;
            ksort($tables);
            foreach ($tables as $table) {
                $this->pdo->exec('LOCK TABLE '.self::quote($table['schema']).'.'.self::quote($table['name']).' IN ACCESS EXCLUSIVE MODE');
            }
            $inside = $this->inspect();

            if ($inside->fingerprint() !== $plan['before_hash']) {
                throw new RuntimeException('Schema changed while acquiring DDL locks.');
            }
            foreach ($plan['operations'] as $operation) {
                foreach ($operation['sql'] as $sql) {
                    $this->pdo->exec($sql);
                }
            }
            $after = $this->inspect();

            if ($after->unsupported !== [] || $after->fingerprint() !== $plan['after_hash']) {
                throw new RuntimeException('Postcondition mismatch. Transaction will be rolled back.');
            }
            $this->pdo->commit();
            $this->pdo->exec('SET default_transaction_read_only = on');
            $confirmed = $this->inspect();
            $confirmedHash = $confirmed->fingerprint();

            if ($confirmedHash !== $plan['after_hash']) {
                throw new RuntimeException('Post-commit schema differs; manual recovery required.');
            }
            $result = ['status' => 'applied', 'fingerprint' => $confirmedHash];
            $this->store->record($id, $result);

            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (! $started) {
                throw $error;
            }

            try {
                $this->pdo->exec('SET default_transaction_read_only = on');
                $observed = $this->inspect();
                $observedHash = $observed->fingerprint();
                $status = $observedHash === $plan['after_hash'] ? 'applied' : ($observedHash === $plan['before_hash'] ? 'rolled_back' : 'divergent');
                $this->store->record($id, ['status' => $status, 'fingerprint' => $observedHash, 'error' => $error->getMessage()]);
            } catch (Throwable) {
                $this->store->record($id, ['status' => 'unknown', 'error' => 'Connection or state inspection failed. Reconnect and explicitly recover.']);
            }

            throw $error;
        } finally {
            $cleanupError = null;

            if ($settings !== null) {
                try {
                    $this->restoreTimeouts($settings);
                } catch (Throwable $error) {
                    $cleanupError = $error;
                }
            }

            if ($locked) {
                try {
                    $this->releaseDatabaseLock();
                } catch (Throwable) {
                    // A lost connection releases its PostgreSQL session lock.
                }
            }
            flock($fileLock, LOCK_UN);
            fclose($fileLock);

            if ($cleanupError !== null) {
                throw new RuntimeException('Cannot restore PostgreSQL session timeouts. Discard this connection and explicitly recover.', 0, $cleanupError);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    private function validatedPlan(string $id, string $expectedSignature, array $binding): array
    {
        TargetConnection::requireRestrictedRole($this->pdo);
        $plan = $this->store->load($id);

        if (($plan['engine'] ?? 'pgsql') !== 'pgsql' || ($plan['execution_mode'] ?? 'transactional') !== 'transactional') {
            throw new RuntimeException('This executor accepts PostgreSQL transactional plans only.');
        }

        if (! hash_equals((string) $plan['signature'], $expectedSignature)) {
            throw new RuntimeException('Approval must equal the reviewed plan signature.');
        }

        if (Snapshot::canonical($binding) !== Snapshot::canonical($plan['binding']) || $this->schemas !== $plan['schemas'] || $this->migrationTable !== $plan['migration_table']) {
            throw new RuntimeException('Plan is bound to another target, environment, or schema scope.');
        }

        return $plan;
    }

    /** @return array<string, string|null> */
    private function sessionTimeouts(): array
    {
        $query = $this->pdo->query("SELECT current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS statement_timeout, current_setting('transaction_timeout', true) AS transaction_timeout");
        $settings = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);

        if (! is_array($settings)) {
            throw new RuntimeException('Cannot inspect PostgreSQL session timeouts.');
        }

        return $settings;
    }

    private function configureTimeouts(int $lockTimeout, int $statementTimeout): void
    {
        $this->pdo->exec('SET SESSION lock_timeout = '.$this->pdo->quote(max(1, $lockTimeout).'ms'));
        $this->pdo->exec('SET SESSION statement_timeout = '.$this->pdo->quote($statementTimeout.'ms'));

        if ((int) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION) >= 17) {
            $this->pdo->exec('SET SESSION transaction_timeout = 0');
        }
    }

    /** @param array<string, string|null> $settings */
    private function restoreTimeouts(array $settings): void
    {
        foreach ($settings as $name => $value) {
            if ($value !== null) {
                $this->pdo->exec('SET SESSION '.$name.' = '.$this->pdo->quote($value));
            }
        }
    }

    private function acquireDatabaseLock(): void
    {
        $query = $this->pdo->query('SELECT pg_try_advisory_lock(1935894631, 1935894632)::int');

        if ($query === false) {
            throw new RuntimeException('Cannot acquire database apply lock.');
        }

        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('Another schema-align apply holds the database lock.');
        }
    }

    private function releaseDatabaseLock(): void
    {
        $query = $this->pdo->query('SELECT pg_advisory_unlock(1935894631, 1935894632)::int');

        if ($query === false || (int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('Cannot release PostgreSQL database apply lock.');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{actual: Snapshot, status: string, fingerprint: string, completed_operations: int}
     */
    private function classify(string $id, array $plan, bool $recover): array
    {
        $actual = $this->inspect();

        if ($actual->identity !== $plan['identity']) {
            throw new RuntimeException('Resolved database identity differs from the approved plan.');
        }

        if ($actual->unsupported !== []) {
            throw new RuntimeException('Incomplete catalog coverage; apply refused.');
        }
        $fingerprint = $actual->fingerprint();
        $status = $fingerprint === $plan['after_hash'] ? 'already_applied' : ($fingerprint === $plan['before_hash'] ? 'pending' : 'divergent');

        if ($status === 'pending') {
            if (! $recover) {
                $state = $this->store->status($id);

                if (($state['status'] ?? '') !== 'planned') {
                    throw new RuntimeException('An earlier attempt exists. Review execution status and pass --recover for verified baseline retry.');
                }
            }
            foreach ($plan['operations'] as $operation) {
                if (! $operation['automatic'] || ! $operation['transactional'] || $operation['sql'] === []) {
                    throw new RuntimeException('Manual or nontransactional operation cannot be automatically applied.');
                }
            }
        }

        return ['actual' => $actual, 'status' => $status, 'fingerprint' => $fingerprint, 'completed_operations' => $status === 'already_applied' ? count($plan['operations']) : 0];
    }

    /** @param array<string, mixed> $classification */
    private function requireApplicable(array $classification): void
    {
        if ($classification['status'] === 'divergent') {
            throw new RuntimeException('Stale or partially divergent plan. Re-introspect and issue a new reviewed plan; no SQL rerun.');
        }
    }

    private function inspect(): Snapshot
    {
        return (new PostgresCatalog($this->pdo, $this->schemas, $this->migrationTable))->inspect();
    }

    private static function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
