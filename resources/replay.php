<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\ConfigurationUrlParser;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Filesystem\WindowsDirectoryPrivacy;
use Korioinc\SchemaAlign\Replay\Connections\ReplayMySqlConnection;
use Korioinc\SchemaAlign\Replay\Connections\ReplayPostgresConnection;
use Korioinc\SchemaAlign\Replay\InstalledAppSource;
use Korioinc\SchemaAlign\Replay\PostgresSearchPath;
use Korioinc\SchemaAlign\Replay\ReplayCompletion;
use Korioinc\SchemaAlign\Replay\ReplayNamespace;
use Korioinc\SchemaAlign\Replay\ReplaySqlGuard;
use Symfony\Component\Console\Output\Output;

// This process executes trusted application code. It is not a PHP sandbox.
$outputBytes = 0;
$outputExceeded = false;
$secrets = [];
$sessions = [];
$lease = null;
$input = [];
ob_start(static function (string $buffer) use (&$outputBytes, &$outputExceeded): string {
    $outputBytes += strlen($buffer);

    if ($outputBytes > 8388608) {
        $outputExceeded = true;
    }

    return '';
}, 8192);

$realPath = static function (string $path): string|false {
    $resolved = realpath($path);

    return $resolved === false ? false : str_replace('\\', '/', $resolved);
};

try {
    $bytes = stream_get_contents(STDIN, 8388609);

    if ($bytes === false || strlen($bytes) > 8388608) {
        throw new RuntimeException('Invalid or oversized replay input.');
    }
    $input = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);

    if (! is_array($input)) {
        throw new RuntimeException('Replay input must be an object.');
    }
    $collectSecrets = static function (array $values) use (&$collectSecrets, &$secrets): void {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $collectSecrets($value);
            } elseif (is_string($value) && $value !== '' && preg_match('/password|secret|token|private|key|username|url/i', (string) $key)) {
                $secrets[] = $value;
                $secrets[] = base64_encode($value);
            }
        }
    };
    $collectSecrets($input);
    $root = $input['root'] ?? null;
    $driver = $input['driver'] ?? null;

    if (! is_string($root) || $realPath($root) !== $root || is_link($root)
        || ! in_array($driver, ['pgsql', 'mysql'], true)
        || ! is_string($input['revision'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $input['revision'])) {
        throw new RuntimeException('Invalid installed application root, driver or revision.');
    }
    foreach (['connection', 'schemas', 'schema_map', 'expected_migrations', 'resource', 'binding', 'identity', 'migration_paths', 'application_config'] as $key) {
        if (! is_array($input[$key] ?? null)) {
            throw new RuntimeException('Missing replay input: '.$key);
        }
    }

    if (($input['connection']['driver'] ?? null) !== $driver
        || ! is_string($input['binding']['instance'] ?? null) || $input['binding']['instance'] === ''
        || ! is_string($input['migration_table'] ?? null) || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $input['migration_table'])
        || $input['migration_table'] === '__schema_align_run') {
        throw new RuntimeException('Invalid replay binding, connection or migration ledger.');
    }

    if (isset($input['connection']['direct']) || isset($input['connection']['read']) || isset($input['connection']['write'])) {
        throw new RuntimeException('Replay requires one connection; direct/read/write routing is unsupported.');
    }
    $mapping = $input['schema_map'];

    if (($input['resource']['schema_map'] ?? null) !== $mapping) {
        throw new RuntimeException('Worker namespace mapping differs from the owned resource.');
    }
    $schemas = $input['schemas'];

    if (! array_is_list($schemas) || $schemas === [] || count($mapping) !== count($schemas)) {
        throw new RuntimeException('Replay requires an exact logical namespace mapping.');
    }
    $logical = array_map(strval(...), array_keys($mapping));
    sort($logical, SORT_STRING);
    $scope = $schemas;
    sort($scope, SORT_STRING);

    if ($logical !== $scope || count(array_unique(array_values($mapping), SORT_STRING)) !== count($mapping)) {
        throw new RuntimeException('Invalid replay namespace mapping.');
    }
    foreach ($mapping as $original => $physical) {
        if ((string) $original === '' || ! is_string($physical) || ! preg_match('/\Aalign-[0-9]{4}-[a-f0-9]{5}\z/', $physical)
            || in_array($physical, $schemas, true)) {
            throw new RuntimeException('Invalid replay namespace.');
        }
    }
    ksort($mapping, SORT_STRING);
    $private = $input['runtime'] ?? null;
    $autoload = $input['autoload'] ?? null;

    if (! is_string($private) || $realPath($private) !== $private || ! is_dir($private) || is_link($private)
        || $private === $root || str_starts_with($private, $root.'/')
        || ! is_string($autoload) || $realPath($autoload) !== $autoload || ! is_file($autoload)) {
        throw new RuntimeException('Invalid private runtime directory or installed autoloader.');
    }
    foreach (['env', 'cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        if (! mkdir($private.'/'.$path, 0700, true)) {
            throw new RuntimeException('Cannot create private worker runtime paths.');
        }
    }
    foreach (['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS'] as $cache) {
        $name = 'APP_'.$cache.'_CACHE';
        $value = $private.'/cache/'.strtolower($cache).'.php';
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    putenv('COMPOSER_VENDOR_DIR='.dirname($autoload));
    $_ENV['COMPOSER_VENDOR_DIR'] = $_SERVER['COMPOSER_VENDOR_DIR'] = dirname($autoload);
    require $autoload;
    // Use the current coordinator package even with classmap-authoritative installed metadata.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Korioinc\\SchemaAlign\\';

        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));

            if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\\\\[A-Za-z][A-Za-z0-9_]*)*\z/D', $relative)) {
                $relative = str_replace('\\', '/', $relative);
                $file = dirname(__DIR__).'/src/'.$relative.'.php';

                if (is_file($file)) {
                    require $file;
                }
            }
        }
    }, true, true);
    $leasePdo = TargetConnection::writable($input['connection'], $input['binding']['instance']);
    $lease = new ReplayNamespace($leasePdo, $input['binding'], $input['identity'], $schemas);
    $lease->workerLease($input['resource']);
    $connectionConfig = $input['connection'];
    unset($connectionConfig['url'], $connectionConfig['read'], $connectionConfig['write']);
    $connectionConfig['schema_align_schema_map'] = $driver === 'pgsql' ? $mapping : [$connectionConfig['database'] => reset($mapping)];

    if ($driver === 'pgsql') {
        unset($connectionConfig['search_path'], $connectionConfig['schema']);
    } else {
        $connectionConfig['database'] = reset($mapping);
    }
    // The application environment is never loaded from its source tree.
    $app = require $root.'/bootstrap/app.php';

    if (! $app instanceof Application) {
        throw new RuntimeException('Installed bootstrap must return a Laravel application.');
    }
    $app->useEnvironmentPath($private.'/env');
    $app->loadEnvironmentFrom('.schema-align-no-environment');
    $app->useStoragePath($private.'/storage');
    $originalStorage = $input['storage_path'] ?? null;

    if (! is_string($originalStorage) || $originalStorage === '') {
        throw new RuntimeException('Missing original application storage path.');
    }
    $paths = [rtrim(str_replace('\\', '/', $originalStorage), '/') => $app->storagePath()];
    $resolvedStorage = $realPath($originalStorage);

    if ($resolvedStorage !== false) {
        $paths[rtrim($resolvedStorage, '/')] = $app->storagePath();
    }
    // Only operational storage moves; application and package source paths stay installed.
    $relocate = static function (mixed $value) use (&$relocate, $paths): mixed {
        if (is_array($value)) {
            return array_map($relocate, $value);
        }

        if (is_string($value)) {
            $path = str_replace('\\', '/', $value);
            foreach ($paths as $original => $destination) {
                if ($path === $original || str_starts_with($path, $original.'/')) {
                    return $destination.substr($path, strlen($original));
                }
            }
        }

        return $value;
    };
    // A real private cache also prevents mergeConfigFrom from repeating numeric-list merges on Laravel 12.
    $configCache = '<?php return '.var_export($relocate($input['application_config']), true).';';
    $configPath = $app->getCachedConfigPath();
    foreach (['Config', 'Services', 'Packages', 'Routes', 'Events'] as $cache) {
        $method = 'getCached'.$cache.'Path';
        $expected = $private.'/cache/'.strtolower($cache).'.php';

        if (str_replace('\\', '/', $app->$method()) !== $expected || is_link($expected)) {
            throw new RuntimeException('Application cache paths must use the private replay runtime.');
        }
    }

    if (PHP_OS_FAMILY === 'Windows') {
        WindowsDirectoryPrivacy::assertPrivate(dirname($configPath), inheritanceRequired: true);
    }

    if (file_put_contents($configPath, $configCache) !== strlen($configCache) || ! chmod($configPath, 0600)) {
        throw new RuntimeException('Cannot write private replay configuration cache.');
    }
    $app->instance(RegisterProviders::class, new class extends RegisterProviders
    {
        public function bootstrap(Illuminate\Contracts\Foundation\Application $app): void
        {
            // Bootstrap provider files may define classes that are not available through Composer.
            $this->mergeAdditionalProviders($app);
            parent::bootstrap($app);
        }
    });
    $assertOutput = static function () use (&$outputExceeded): void {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        if ($outputExceeded) {
            throw new RuntimeException('Application output exceeded 8 MiB.');
        }
    };
    $factory = null;
    $replayConnectionName = '__schema_align_replay_'.bin2hex(random_bytes(8));
    $assertTarget = static function (array $config) use ($input, $driver): void {
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $instance = $input['binding']['instance'];
        $environment = $input['binding']['environment'];

        if (($config['driver'] ?? null) !== $driver
            || isset($config['direct']) || isset($config['read']) || isset($config['write'])
            || TargetConnection::binding($config, $instance, $environment) !== TargetConnection::binding($input['connection'], $instance, $environment)) {
            throw new RuntimeException('unsupported_replay_connection: only aliases of the selected target without direct/read/write routing can replay.');
        }
    };
    $assertFactory = static function () use ($driver, &$factory, $app): void {
        if (Connection::getResolver($driver) !== $factory) {
            throw new RuntimeException('unsupported_connection_resolver: replay factory was replaced.');
        }

        if ($app->resolved('db') && (new ReflectionProperty(DatabaseManager::class, 'extensions'))->getValue($app->make('db')) !== []) {
            throw new RuntimeException('unsupported_connection_resolver: custom database manager extensions cannot replay.');
        }
    };
    $factory = static function ($ignoredPdo, $database, $prefix, array $config) use (&$sessions, $input, $driver, $connectionConfig, $lease, $assertFactory, $assertTarget, $assertOutput) {
        $assertFactory();
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $assertTarget($config);
        foreach (['options', 'server_options'] as $key) {
            $requested = $config[$key] ?? [];
            $selected = $connectionConfig[$key] ?? [];

            if (! is_array($requested) || ! is_array($selected)) {
                throw new RuntimeException('unsupported_replay_session_config: '.$key.' must be an array.');
            }
            ksort($requested);
            ksort($selected);

            if ($requested !== $selected) {
                throw new RuntimeException('unsupported_replay_session_config: auxiliary '.$key.' must match the selected connection.');
            }
        }
        // Preserve local DDL and connector session defaults, including absence, before opening the PDO.
        // Endpoint credentials, low-level startup options and owned namespace mapping remain managed.
        $localOptions = array_flip([
            'prefix', 'prefix_indexes', 'charset', 'collation', 'engine', 'timezone', 'isolation_level',
            'application_name', 'synchronous_commit', 'modes', 'strict', 'version',
        ]);
        $sessionConfig = array_replace(
            array_diff_key($connectionConfig, $localOptions),
            array_intersect_key($config, $localOptions),
        );

        if ($driver === 'pgsql') {
            $sessionConfig['search_path'] = (new PostgresSearchPath)->map($config, $input['schema_map']);
        }
        // Ignore lazy host/failover factories. Open one explicit connection and register it first.
        $pdo = TargetConnection::writable($sessionConfig, $input['binding']['instance']);
        $lease->registerSession($input['resource'], $pdo);
        $class = $driver === 'pgsql' ? ReplayPostgresConnection::class : ReplayMySqlConnection::class;
        $managedConfig = array_replace($sessionConfig, ['name' => $config['name'] ?? $driver]);
        $connection = new $class($pdo, $managedConfig['database'], $managedConfig['prefix'] ?? '', $managedConfig);
        $sessions[] = $connection;
        $connection->beforeExecuting(static function (string $sql, array $bindings, Connection $managed) use ($input, $driver, $assertFactory, $lease, $assertOutput): void {
            $assertFactory();
            $assertOutput();
            $lease->registerSession($input['resource'], $managed->getPdo());
            // Recognizable managed-session escapes fail before SQL. Trusted PHP can bypass this guard.
            $code = preg_replace("/'(?:''|[^'])*'/s", "''", $sql);

            if ($code === null) {
                throw new RuntimeException('Unsupported or malformed replay SQL.');
            }
            $originals = $driver === 'pgsql' ? $input['schemas'] : [$input['connection']['database']];
            ReplaySqlGuard::assertAllowedNamespaces($sql, $originals, array_values($input['schema_map']), $driver);

            if (str_contains($code, '__schema_align_run')) {
                throw new RuntimeException('The replay ownership marker is reserved.');
            }
        });

        return $connection;
    };
    $app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($input, $driver, $factory, $assertFactory, $assertTarget, $replayConnectionName): void {
        foreach (['pgsql', 'mysql'] as $engine) {
            if (Connection::getResolver($engine) !== null) {
                throw new RuntimeException('unsupported_connection_resolver: custom connection factories cannot replay.');
            }
        }
        Connection::resolverFor($driver, $factory);

        if ($app->resolved('db')) {
            $manager = $app->make('db');
            foreach (array_keys($manager->getConnections()) as $name) {
                $manager->purge($name);
            }
        }
        // Container extenders also apply to the DB binding registered later by Laravel.
        $app->extend('db', static function ($manager, $app) use ($assertFactory, $assertTarget) {
            if (get_class($manager) !== DatabaseManager::class
                || (new ReflectionProperty(DatabaseManager::class, 'extensions'))->getValue($manager) !== []) {
                throw new RuntimeException('unsupported_connection_resolver: custom database managers cannot replay.');
            }

            return new class($app, $app['db.factory'], $assertFactory, $assertTarget) extends DatabaseManager
            {
                private Closure $assertFactory;

                private Closure $assertTarget;

                public function __construct($app, $factory, Closure $assertFactory, Closure $assertTarget)
                {
                    parent::__construct($app, $factory);
                    $this->assertFactory = $assertFactory;
                    $this->assertTarget = $assertTarget;
                }

                protected function makeConnection($name)
                {
                    ($this->assertFactory)();
                    ($this->assertTarget)($this->configuration($name));

                    return parent::makeConnection($name);
                }

                public function connectUsing(UnitEnum|string $name, array $config, bool $force = false)
                {
                    $config = (new ConfigurationUrlParser)->parseConfiguration($config);
                    ($this->assertFactory)();
                    ($this->assertTarget)($config);

                    return parent::connectUsing($name, $config, $force);
                }

                public function extend($name, callable $resolver): void
                {
                    throw new RuntimeException('unsupported_connection_resolver: custom database extensions cannot replay.');
                }
            };
        });
        // Keep original named targets available for validation before any redirection.
        $connections = $app['config']->get('database.connections', []);
        $connections[$replayConnectionName] = $input['connection'];
        $app['config']->set('database.default', $replayConnectionName);
        $app['config']->set('database.connections', $connections);
        $app['config']->set('database.migrations', ['table' => $input['migration_table'], 'update_date_on_publish' => true]);
        $app['config']->set('schema-align.replay.schema_map', $driver === 'pgsql' ? $input['schema_map'] : []);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('view.compiled', $app->storagePath('framework/views'));
        $app['config']->set('logging.default', 'single');
        $app['config']->set('logging.channels.single.path', $app->storagePath('logs/replay.log'));
    });
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $assertFactory();
    $assertOutput();
    $migrator = $app->make('migrator');
    $verifySource = static function () use ($app, $input, $root): array {
        $paths = InstalledAppSource::paths($app, $input['migration_paths']);
        $observed = InstalledAppSource::manifest($paths);
        $lock = file_get_contents($root.'/composer.lock');

        if ($lock === false || $observed !== $input['expected_migrations']
            || InstalledAppSource::revision($lock, $observed) !== $input['revision']) {
            throw new RuntimeException('Installed migrations or composer.lock changed before or during replay.');
        }

        return ['paths' => $paths, 'manifest' => $observed, 'lock' => $lock];
    };
    $source = $verifySource();
    $paths = $source['paths'];
    $names = [];
    foreach (array_keys($source['manifest']) as $path) {
        if (! str_ends_with($path, '/')) {
            $names[] = pathinfo($path, PATHINFO_FILENAME);
        }
    }
    sort($names, SORT_STRING);
    $digest = ReplayCompletion::digest($input['revision'], $source['lock'], $source['manifest']);
    $repository = $migrator->getRepository();

    if ($repository->repositoryExists() && $repository->getRan() !== []) {
        throw new RuntimeException('Replay requires an empty migration ledger.');
    }
    $boundedOutput = new class extends Output
    {
        private int $bytes = 0;

        protected function doWrite(string $message, bool $newline): void
        {
            $this->bytes += strlen($message) + (int) $newline;

            if ($this->bytes > 8388608) {
                throw new RuntimeException('Migration console output exceeded 8 MiB.');
            }
        }
    };
    $status = $kernel->call('migrate', [
        '--force' => true, '--no-interaction' => true, '--database' => $replayConnectionName,
        '--path' => $paths, '--realpath' => true, '--schema-path' => $private.'/no-schema-dump.sql',
    ], $boundedOutput);

    if ($status !== 0) {
        throw new RuntimeException('Complete migration replay failed; no migrations may be skipped.');
    }
    $assertOutput();
    $ran = $repository->getRan();
    sort($ran, SORT_STRING);

    if ($ran !== $names) {
        throw new RuntimeException('Migration ledger differs from complete source set; skipped migrations are forbidden.');
    }
    $verifySource();
    foreach ($sessions as $session) {
        $session->disconnect();
    }
    $sessions = [];
    $lease->releaseWorker();
    $lease->release();
    $lease = null;
    $result = ReplayCompletion::complete($digest, $ran);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($outputExceeded) {
        throw new RuntimeException('Application output exceeded 8 MiB.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    foreach ($sessions as $session) {
        $session->disconnect();
    }

    if ($lease !== null) {
        try {
            $lease->releaseWorker();
            $lease->release();
        } catch (Throwable) {
            // Parent performs independent lease/session verification before cleanup.
        }
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $message = $error->getMessage();
    usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $message = str_replace($secrets, '[REDACTED]', $message);
    fwrite(STDERR, 'Complete trusted replay failed: '.substr($message, -4096)."\n");
    exit(1);
}
