<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use Korioinc\SchemaAlign\Database\EngineConfiguration;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Execution\ApplyExecutor;
use Korioinc\SchemaAlign\Execution\MySqlApplyExecutor;
use Korioinc\SchemaAlign\Planning\Operation;
use Korioinc\SchemaAlign\Planning\PlanStore;
use Korioinc\SchemaAlign\Replay\InstalledAppSource;
use Korioinc\SchemaAlign\Replay\MySqlReplayer;
use Korioinc\SchemaAlign\Replay\ReleaseReplayer;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\MySqlComparator;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;
use RuntimeException;
use Throwable;

final class SchemaAlign
{
    public function __construct(private readonly Application $app) {}

    /** @return array<string, mixed> */
    public function diff(): array
    {
        $config = $this->configuration();
        $connection = $this->connectionConfig();
        $pdo = TargetConnection::readOnly($connection, $config['target_instance']);
        $connectionWarnings = TargetConnection::warnings($pdo);
        $actual = $config['driver'] === 'pgsql'
            ? (new PostgresCatalog($pdo, $config['schemas'], $config['migration_table_physical']))->inspect()
            : (new MySqlCatalog($pdo, $config['migration_table_physical']))->inspect();

        if ($actual->unsupported !== []) {
            throw new RuntimeException('Original catalog is incomplete or unsupported; no replay namespace was created: '.json_encode($actual->unsupported, JSON_THROW_ON_ERROR));
        }
        $replay = $config['replay'];
        $replay['schemas'] = $config['schemas'];
        $replay['migration_table'] = $config['migration_table'];
        $replay['target_platform'] = $actual->platform;
        $replay['connection'] = $connection;
        $replay['binding'] = TargetConnection::binding($connection, $config['target_instance'], $this->app->environment());
        $replay['identity'] = $actual->identity;
        $replay['timezone'] = $this->app->make('config')->get('app.timezone') ?? 'UTC';
        $replay['application_config'] = $this->app->make('config')->all();
        $replay['storage_path'] = $this->app->storagePath();
        $installed = (new InstalledAppSource)->inspect($this->app, $replay['migration_paths']);
        $replayer = $config['driver'] === 'pgsql' ? new ReleaseReplayer : new MySqlReplayer;
        $captured = $replayer->capture(array_replace($replay, $installed));
        $captured['source']['preparation'] = $installed['source'];
        $desired = $captured['snapshot'];
        $comparator = $config['driver'] === 'pgsql' ? new SchemaComparator : new MySqlComparator;
        $comparison = $comparator->compare($desired, $actual);
        $comparison['warnings'] = array_values(array_unique([...$comparison['warnings'], ...$connectionWarnings]));
        $comparison['connection_warnings'] = $connectionWarnings;
        $comparison['desired'] = $desired->toArray();
        $comparison['actual'] = $actual->toArray();
        $comparison['source'] = $captured['source'];
        $comparison['binding'] = TargetConnection::binding($this->connectionConfig(), $config['target_instance'], $this->app->environment());
        $comparison['status'] = $comparison['errors'] !== [] ? 'failure' : ($comparison['differences'] === [] ? 'clean' : 'drift');

        return $comparison;
    }

    /** @param list<string> $selection
     * @return array<string, mixed>
     */
    public function plan(array $selection): array
    {
        return $this->pin($this->diff(), $selection);
    }

    /** Pin an already inspected comparison; source and SQL remain fixed after this action.
     * @param  array<string, mixed>  $comparison
     * @param  list<string>  $selection
     * @return array<string, mixed>
     */
    public function pin(array $comparison, array $selection): array
    {
        $config = $this->configuration();

        if ($comparison['errors'] !== []) {
            throw new RuntimeException('Incomplete comparison cannot become an executable plan: '.implode('; ', $comparison['errors']));
        }

        if ($selection === []) {
            throw new RuntimeException('Select operation IDs or all-supported.');
        }
        $operations = [];
        $allSupported = $selection === ['all-supported'];
        foreach ($comparison['differences'] as $difference) {
            if ($allSupported ? Operation::isExecutable($difference) : in_array($difference['id'], $selection, true)) {
                if (! Operation::isExecutable($difference) || ($config['driver'] === 'pgsql' && ! $difference['transactional'])
                    || ($config['driver'] === 'mysql' && ($difference['kind'] === 'drop_column' || $difference['transactional'] || count($difference['sql']) !== 1))) {
                    throw new RuntimeException('Selected operation requires manual decisions: '.$difference['table'].'.'.$difference['name'].' '.$difference['id'].' '.($difference['reason'] ?? 'unsupported transformation'));
                }
                $operations[] = $difference;
            }
        }

        if (! $allSupported && count($operations) !== count(array_unique($selection))) {
            throw new RuntimeException('Selection contains unknown operation IDs.');
        }

        if ($operations === []) {
            $details = array_map(static fn (array $difference): string => $difference['table'].'.'.$difference['name'].' ('.$difference['kind'].'): '.($difference['reason'] ?? 'Not selected.'), $comparison['differences']);

            throw new RuntimeException('No executable operations selected. Review these changes: '.implode('; ', $details));
        }
        $ids = array_column($operations, 'id');
        $deferred = array_values(array_filter($comparison['differences'], static fn (array $difference): bool => ! in_array($difference['id'], $ids, true)));
        foreach ($operations as $operation) {
            foreach ($operation['dependencies'] as $dependency) {
                if (! in_array($dependency, $ids, true)) {
                    throw new RuntimeException('Select prerequisite operation '.$dependency.' for '.$operation['id']);
                }
            }
        }
        $pending = $operations;
        $operations = [];
        $done = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $index => $operation) {
                if (array_diff($operation['dependencies'], $done) === []) {
                    $operations[] = $operation;
                    $done[] = $operation['id'];
                    unset($pending[$index]);
                    $progress = true;
                }
            }

            if (! $progress) {
                throw new RuntimeException('Cyclic or missing plan dependencies require manual review.');
            }
        }
        $before = Snapshot::fromArray($comparison['actual']);

        if (($before->platform['engine'] ?? 'pgsql') !== $config['driver']) {
            throw new RuntimeException('Comparison engine differs from the selected application connection.');
        }
        $projection = Operation::withCheckpoints($before, $operations);

        return $this->store()->save([
            'engine' => $config['driver'], 'execution_mode' => $config['driver'] === 'pgsql' ? 'transactional' : 'checkpointed',
            'binding' => $comparison['binding'], 'identity' => $before->identity,
            'schemas' => $config['schemas'], 'migration_table' => $config['migration_table_physical'],
            'source' => $comparison['source'], 'before_hash' => $projection['before_hash'],
            'after_hash' => $projection['after_hash'], 'operations' => $projection['operations'],
            'deferred_changes' => $deferred,
            'warnings' => $comparison['warnings'],
        ]);
    }

    /** Inspect the exact signed plan against the live database without replaying or applying SQL.
     * @return array<string, mixed>|null
     */
    public function reviewPlan(?string $id = null, bool $recover = false): ?array
    {
        $config = $this->configuration();
        $plan = $id === null ? $this->store()->latest() : $this->store()->load($id);

        if ($plan === null) {
            return null;
        }
        $connection = $this->connectionConfig();
        $binding = TargetConnection::binding($connection, $config['target_instance'], $this->app->environment());

        if (Snapshot::canonical($binding) !== Snapshot::canonical($plan['binding'] ?? [])
            || ($plan['engine'] ?? 'pgsql') !== $config['driver']
            || ($plan['schemas'] ?? null) !== $config['schemas']
            || ($plan['migration_table'] ?? null) !== $config['migration_table_physical']) {
            throw new RuntimeException('Saved plan belongs to another target, account, environment or schema scope. Run schema-align:plan for the current connection.');
        }
        $pdo = TargetConnection::connect($connection, $config['target_instance']);
        $executor = $config['driver'] === 'pgsql'
            ? new ApplyExecutor($pdo, $this->store(), $config['schemas'], $config['migration_table_physical'])
            : new MySqlApplyExecutor($pdo, $this->store(), $config['migration_table_physical']);

        return $executor->review($plan['id'], $plan['signature'], $binding, $recover, $config['lock_timeout_ms']);
    }

    /** @param int|null $statementTimeout PostgreSQL milliseconds; null uses configuration, 0 disables the limit.
     * @return array<string, mixed>
     */
    public function apply(string $id, string $approval, bool $recover = false, ?int $statementTimeout = null, bool $allowDropColumns = false): array
    {
        $config = $this->configuration();
        $statementTimeout ??= $config['statement_timeout_ms'];

        if ($statementTimeout < 0 || $statementTimeout > 2147483647 || ($config['driver'] === 'mysql' && $statementTimeout !== 0)) {
            throw new RuntimeException('Apply timeout must be 0..2147483647 milliseconds for PostgreSQL, or 0 for MySQL DDL.');
        }
        $pdo = TargetConnection::connect($this->connectionConfig(), $config['target_instance']);

        $executor = $config['driver'] === 'pgsql'
            ? new ApplyExecutor($pdo, $this->store(), $config['schemas'], $config['migration_table_physical'])
            : new MySqlApplyExecutor($pdo, $this->store(), $config['migration_table_physical']);

        $result = $executor->apply(
            $id, $approval,
            TargetConnection::binding($this->connectionConfig(), $config['target_instance'], $this->app->environment()),
            $recover, $config['lock_timeout_ms'], $statementTimeout, $allowDropColumns,
        );

        try {
            return array_replace($result, $this->finishApply($id, $approval));
        } catch (Throwable $error) {
            // Original DDL is already committed; cleanup failure must not be presented as rollback.
            $result['status'] = 'applied_cleanup_blocked';
            $result['apply_status'] = 'applied';
            $result['cleanup'] = ['status' => 'cleanup_blocked', 'errors' => [$error->getMessage()]];
            $this->store()->record($id, $result);

            return $result;
        }
    }

    /** Finalize only a live-verified completed plan; never execute its original SQL.
     * @return array<string, mixed>
     */
    public function finishApply(string $id, string $signature): array
    {
        $store = $this->store();
        $lock = $store->lock($id);

        try {
            $review = $this->reviewPlan($id);

            if ($review === null || ! hash_equals($review['plan']['signature'], $signature)) {
                throw new RuntimeException('Approval must equal the reviewed plan signature.');
            }

            if ($review['status'] !== 'already_applied') {
                throw new RuntimeException('Cleanup finalization requires a live-verified fully applied plan.');
            }
            $plan = $review['plan'];
            $replay = $plan['source']['replay'] ?? null;
            $cleanup = ['status' => 'not_required', 'runs' => []];

            if ($replay !== null) {
                try {
                    $config = $this->configuration();
                    $map = $replay['schema_map'] ?? null;

                    if (! is_array($replay) || ($replay['strategy'] ?? null) !== 'namespace'
                        || ($replay['engine'] ?? null) !== $config['driver']
                        || ($replay['database'] ?? null) !== $plan['binding']['database']
                        || ! is_string($replay['run_id'] ?? null) || ! preg_match('/\A[a-f0-9]{32}\z/D', $replay['run_id'])
                        || ! is_array($map) || array_map(strval(...), array_keys($map)) !== $plan['schemas']) {
                        throw new RuntimeException('Signed replay ownership evidence is incomplete; automatic cleanup refused.');
                    }
                    foreach ($map as $name) {
                        if (! is_string($name) || ! preg_match('/\Aalign-[0-9]{4}-[a-f0-9]{5}\z/D', $name)) {
                            throw new RuntimeException('Signed replay namespace evidence is invalid; automatic cleanup refused.');
                        }
                    }
                    $cleanup = ReleaseReplayer::cleanupOwned($this->connectionConfig(), $plan['binding'], $plan['identity'], $plan['schemas'], InstalledAppSource::autoload($this->app->basePath()));
                } catch (Throwable $error) {
                    $cleanup = ['status' => 'cleanup_blocked', 'errors' => [$error->getMessage()], 'runs' => []];
                }
            }
            $result = [
                'status' => $cleanup['status'] === 'cleanup_blocked' ? 'applied_cleanup_blocked' : 'applied',
                'apply_status' => 'applied', 'plan' => $id, 'fingerprint' => $review['fingerprint'],
                'completed_operations' => $review['completed_operations'], 'cleanup' => $cleanup,
            ];

            $state = $store->status($id);

            if (($state['status'] ?? null) !== $result['status'] || ($state['fingerprint'] ?? null) !== $result['fingerprint']
                || ($cleanup['runs'] ?? []) !== [] || $cleanup['status'] === 'cleanup_blocked'
                || ($state['cleanup']['status'] ?? null) === 'cleanup_blocked') {
                $store->record($id, array_replace($state, $result));
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Remove only positively identified, inactive resources of an exact replay run.
     * @return array<string, mixed>
     */
    public function cleanup(string $run): array
    {
        $config = $this->configuration();
        $connection = $this->connectionConfig();

        return ReleaseReplayer::cleanup($connection,
            TargetConnection::binding($connection, $config['target_instance'], $this->app->environment()),
            [], $config['schemas'], $run, InstalledAppSource::autoload($this->app->basePath()));
    }

    public function store(): PlanStore
    {
        return new PlanStore($this->configuration()['plan_directory']);
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $config = $this->app->make('config')->get('schema-align');

        if (! is_array($config)) {
            throw new RuntimeException('Missing schema-align configuration.');
        }
        $resolved = (new ConfigurationUrlParser)->parseConfiguration($this->connectionConfig());
        $driver = $resolved['driver'] ?? null;

        if (! is_string($driver)) {
            throw new RuntimeException('Application connection driver is missing.');
        }
        $config = EngineConfiguration::resolve($config, $driver);
        $config['migration_table_physical'] = EngineConfiguration::physicalMigrationTable($resolved, $config['migration_table']);
        $config['plan_directory'] ??= $this->app->storagePath('app/schema-align');

        if (! is_string($config['target_instance'] ?? null) || $config['target_instance'] === '') {
            $binding = TargetConnection::binding($this->connectionConfig(), '', $this->app->environment());
            $config['target_instance'] = 'app:'.hash('sha256', Snapshot::canonical($binding));
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function connectionConfig(): array
    {
        $config = $this->app->make('config');
        $name = $config->get('schema-align.connection') ?? $config->get('database.default');

        if (! is_string($name) || $name === '') {
            throw new RuntimeException('No pgsql or mysql target connection is configured in database.default or schema-align.connection.');
        }
        $connection = $config->get('database.connections.'.$name);

        if (! is_array($connection)) {
            throw new RuntimeException('Configured target connection does not exist.');
        }

        return $connection;
    }
}
