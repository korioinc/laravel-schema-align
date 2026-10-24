<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use Illuminate\Support\ConfigurationUrlParser;
use Korioinc\SchemaAlign\Database\EngineConfiguration;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\NamespaceNormalizer;
use Korioinc\SchemaAlign\Schema\Snapshot;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Coordinates trusted local PHP replay without changing original objects. */
final class ReleaseReplayer
{
    /** @param array<string, mixed> $config
     * @return array{snapshot: Snapshot, source: array<string, mixed>}
     */
    public function capture(array $config): array
    {
        foreach (['connection', 'binding', 'identity', 'schemas', 'expected_migrations', 'application_config', 'migration_paths'] as $key) {
            if (! is_array($config[$key] ?? null)) {
                throw new RuntimeException('Namespace replay requires '.$key.'.');
            }
        }

        try {
            $encoded = json_encode($config['application_config'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 64);

            if (json_decode($encoded, true, 64, JSON_THROW_ON_ERROR) !== $config['application_config']) {
                throw new RuntimeException('Lossy configuration encoding.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Replay requires application configuration containing only JSON-preservable arrays, scalar values and null.');
        }

        if (! array_is_list($config['schemas']) || $config['schemas'] === []) {
            throw new RuntimeException('Replay requires a nonempty original schema list.');
        }
        $schemas = [];
        foreach ($config['schemas'] as $schema) {
            if (! is_string($schema) || $schema === '') {
                throw new RuntimeException('Invalid original replay schema.');
            }
            $schemas[] = $schema;
        }

        if (! is_string($config['root'] ?? null) || ! is_dir($config['root']) || ! is_string($config['revision'] ?? null)
            || ! is_string($config['autoload'] ?? null) || ! is_file($config['autoload'])
            || ! is_string($config['storage_path'] ?? null) || $config['storage_path'] === '') {
            throw new RuntimeException('Namespace replay requires the installed application and its autoloader.');
        }
        $connection = (new ConfigurationUrlParser)->parseConfiguration($config['connection']);
        $driver = $connection['driver'] ?? null;

        if (! in_array($driver, ['pgsql', 'mysql'], true) || $driver !== ($config['driver'] ?? null)) {
            throw new RuntimeException('Replay connection engine mismatch.');
        }
        $migrationTable = EngineConfiguration::physicalMigrationTable($connection, $config['migration_table']);
        $instance = (string) ($config['binding']['instance'] ?? '');
        $lock = file_get_contents($config['root'].'/composer.lock');

        if ($lock === false) {
            throw new RuntimeException('Installed app must include composer.lock.');
        }

        if (InstalledAppSource::revision($lock, $config['expected_migrations']) !== $config['revision']) {
            throw new RuntimeException('Installed composer.lock changed before replay.');
        }
        $expectedDigest = ReplayCompletion::digest($config['revision'], $lock, $config['expected_migrations']);
        $pdo = TargetConnection::writable($connection, $instance);
        $namespaces = new ReplayNamespace($pdo, $config['binding'], $config['identity'], $schemas);
        $resource = null;
        $result = null;
        $failure = null;
        $secrets = $this->secrets(['connection' => $connection, 'application_config' => $config['application_config']]);
        $process = null;
        $reader = null;
        $workspace = null;

        try {
            $workspace = new ReplayWorkspace;

            if ($workspace->path === $config['root'] || str_starts_with($workspace->path, $config['root'].'/')) {
                throw new RuntimeException('Replay runtime storage must be outside the installed application.');
            }
            $resource = $namespaces->create($config['timezone'] ?? 'UTC');
            $input = array_intersect_key($config, array_flip(['root', 'autoload', 'storage_path', 'revision', 'driver', 'schemas', 'migration_table', 'migration_paths', 'expected_migrations', 'binding', 'identity', 'application_config']));
            $input['connection'] = $connection;
            $input['schema_map'] = $resource['schema_map'];
            $input['resource'] = $resource;
            $input['runtime'] = $workspace->path;
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/resources/replay.php'], $config['root'], null, json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $config['timeout'] ?? 180);
            $output = '';
            $error = '';
            $process->run(static function (string $type, string $bytes) use (&$output, &$error): void {
                if ($type === Process::OUT) {
                    $output .= $bytes;
                } else {
                    $error .= $bytes;
                }

                if (strlen($output) + strlen($error) > 8388608) {
                    throw new RuntimeException('Replay output exceeded 8 MiB.');
                }
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Complete migration replay failed: '.substr($error, -4096));
            }
            $evidence = ReplayCompletion::validate(json_decode($output, true, 512, JSON_THROW_ON_ERROR), $config['expected_migrations'], $expectedDigest);
            $temporary = $connection;
            unset($temporary['url'], $temporary['read'], $temporary['write']);

            if ($driver === 'pgsql') {
                $temporary['search_path'] = array_values($resource['schema_map']);
            } else {
                $temporary['database'] = array_values($resource['schema_map'])[0];
            }
            $reader = TargetConnection::readOnly($temporary, $instance);

            if ($driver === 'mysql') {
                $name = array_values($resource['schema_map'])[0];
                $statement = $pdo->query('SELECT payload FROM `'.str_replace('`', '``', $name).'`.`__schema_align_run`');

                if ($statement === false) {
                    throw new RuntimeException('Cannot inspect replay ownership marker.');
                }
                $markers = $statement->fetchAll(PDO::FETCH_COLUMN);
                $current = count($markers) === 1 ? json_decode($markers[0], true, 64, JSON_THROW_ON_ERROR) : null;

                if (! is_array($current)) {
                    throw new RuntimeException('Replay ownership marker is incomplete.');
                }
                $immutable = $current;
                $expected = $resource;
                unset($immutable['sessions'], $immutable['state'], $expected['sessions'], $expected['state']);

                if (Snapshot::canonical($immutable) !== Snapshot::canonical($expected)) {
                    throw new RuntimeException('Replay ownership changed during execution.');
                }
                $resource = $current;
            }
            $physical = $driver === 'pgsql'
                ? (new PostgresCatalog($reader, array_values($resource['schema_map']), $migrationTable))->inspect()
                : (new MySqlCatalog($reader, $migrationTable, $resource))->inspect();
            $snapshot = $driver === 'pgsql' ? NamespaceNormalizer::normalize($physical, $resource['schema_map']) : $physical;
            $reader = null;
            $result = ['snapshot' => $snapshot, 'source' => [
                'revision' => $config['revision'],
                'migration_digest' => $evidence['migration_digest'],
                'migration_names' => $evidence['migration_names'],
                'naming_strategy' => $evidence['naming_strategy'],
                'replay' => ['strategy' => 'namespace', 'engine' => $driver, 'run_id' => $resource['run_id'],
                    'database' => $connection['database'], 'schema_map' => $resource['schema_map'],
                    'identity' => $physical->identity, 'fresh' => true],
            ]];
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $reader = null;

            try {
                if ($process !== null && $process->isRunning()) {
                    $process->stop(1);
                }

                if (is_array($resource)) {
                    try {
                        $namespaces->release();
                        $cleanup = self::cleanup($connection, $config['binding'], $config['identity'], $schemas, $resource['run_id'], $config['autoload']);

                        if ($result !== null) {
                            $result['source']['replay']['cleanup'] = $cleanup;
                        }
                    } catch (Throwable $cleanupError) {
                        $message = ($failure !== null ? $failure->getMessage().'; ' : '').'cleanup_blocked for run '.$resource['run_id'].' namespaces '.implode(', ', array_values($resource['schema_map'])).': '.$cleanupError->getMessage().'. Review and run schema-align:cleanup '.$resource['run_id'];
                        $failure = new RuntimeException($message, 0, $failure ?? $cleanupError);
                    }
                }
            } catch (Throwable $cleanupError) {
                $message = ($failure !== null ? $failure->getMessage().'; ' : '').'Replay worker termination failed: '.$cleanupError->getMessage();
                $failure = new RuntimeException($message, 0, $failure ?? $cleanupError);
            } finally {
                try {
                    $namespaces->release();
                } catch (Throwable $cleanupError) {
                    $message = ($failure !== null ? $failure->getMessage().'; ' : '').'Replay coordinator release failed: '.$cleanupError->getMessage();
                    $failure = new RuntimeException($message, 0, $failure ?? $cleanupError);
                } finally {
                    if ($workspace !== null) {
                        try {
                            if ($process !== null && $process->isRunning()) {
                                throw new RuntimeException('Worker is still running; private runtime retained at '.$workspace->path);
                            }
                            $workspace->remove();
                        } catch (Throwable $cleanupError) {
                            $message = ($failure !== null ? $failure->getMessage().'; ' : '').'Private replay runtime cleanup failed: '.$cleanupError->getMessage();
                            $failure = new RuntimeException($message, 0, $failure ?? $cleanupError);
                        }
                    }
                }
            }
        }

        if ($failure !== null) {
            throw new RuntimeException($this->redact($failure->getMessage(), $secrets));
        }

        if ($result === null) {
            throw new RuntimeException('Replay produced no complete snapshot.');
        }

        return $result;
    }

    /** Execute cleanup outside this process so MySQL DDL cannot block its caller indefinitely.
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $schemas
     * @return array<string, mixed>
     */
    public static function cleanup(array $connection, array $binding, array $identity, array $schemas, string $run, string $autoload): array
    {
        try {
            $result = self::cleanupProcess(compact('connection', 'binding', 'identity', 'schemas', 'run', 'autoload'));

            if (! in_array($result['status'] ?? null, ['cleaned', 'already_absent'], true)) {
                throw new RuntimeException('Cleanup outcome is unconfirmed.');
            }

            return $result;
        } catch (Throwable $failure) {
            throw new RuntimeException('cleanup_blocked: run '.$run.'; removal outcome unconfirmed. '.$failure->getMessage());
        }
    }

    /** @param array<string, mixed> $connection
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $schemas
     * @return array<string, mixed>
     */
    public static function cleanupOwned(array $connection, array $binding, array $identity, array $schemas, string $autoload): array
    {
        $input = compact('connection', 'binding', 'identity', 'schemas', 'autoload');
        $input['mode'] = 'owned_runs';

        try {
            $result = self::cleanupProcess($input);
            $statuses = ['cleaned', 'already_absent', 'cleanup_blocked'];

            if (! in_array($result['status'] ?? null, $statuses, true)
                || ! is_array($result['runs'] ?? null) || ! array_is_list($result['runs']) || count($result['runs']) > 100) {
                throw new RuntimeException('Cleanup outcome is unconfirmed.');
            }
            $redactor = new self;
            $secrets = $redactor->secrets($connection);
            $redactErrors = static fn (array $errors): array => array_map(
                static fn (string $message): string => $redactor->redact($message, $secrets), $errors,
            );

            if (isset($result['errors'])) {
                $result['errors'] = $redactErrors($result['errors']);
            }
            foreach ($result['runs'] as &$run) {
                if (! is_array($run) || ! in_array($run['status'] ?? null, $statuses, true)) {
                    throw new RuntimeException('Cleanup run outcome is unconfirmed.');
                }

                if (isset($run['errors'])) {
                    $run['errors'] = $redactErrors($run['errors']);
                }
            }
            unset($run);

            return $result;
        } catch (Throwable $failure) {
            return ['status' => 'cleanup_blocked', 'runs' => [], 'errors' => ['Owned cleanup outcome is unconfirmed. '.$failure->getMessage()]];
        }
    }

    /** One process deadline covers discovery and every run in the batch.
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function cleanupProcess(array $input): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/resources/cleanup.php'], null, null, json_encode($input, JSON_THROW_ON_ERROR), 30);
        $output = '';
        $error = '';

        try {
            $process->run(static function (string $type, string $bytes) use (&$output, &$error): void {
                if ($type === Process::OUT) {
                    $output .= $bytes;
                } else {
                    $error .= $bytes;
                }

                if (strlen($output) + strlen($error) > 8388608) {
                    throw new RuntimeException('Cleanup output exceeded 8 MiB.');
                }
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException(substr($error, -4096));
            }
            $result = json_decode($output, true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($result)) {
                throw new RuntimeException('Cleanup outcome is unconfirmed.');
            }

            return $result;
        } catch (Throwable $failure) {
            $message = (new self)->redact($failure->getMessage(), (new self)->secrets($input['connection']));

            throw new RuntimeException($message);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /** @param array<string, mixed> $config
     * @return list<string>
     */
    private function secrets(array $config): array
    {
        $secrets = [];
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $secrets = [...$secrets, ...$this->secrets($value)];
            } elseif (is_string($value) && $value !== '' && preg_match('/password|secret|token|private|key|username|url/i', (string) $key)) {
                $secrets[] = $value;
                $secrets[] = base64_encode($value);
            }
        }

        return $secrets;
    }

    /** @param list<string> $secrets */
    private function redact(string $message, array $secrets): string
    {
        return str_replace($secrets, '[redacted]', $message);
    }
}
