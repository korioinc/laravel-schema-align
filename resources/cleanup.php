<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Replay\ReplayNamespace;

// Isolated from application providers and bounded by the coordinating process deadline.
$input = [];
$outputBytes = 0;
ob_start(static function (string $bytes) use (&$outputBytes): string {
    $outputBytes += strlen($bytes);

    if ($outputBytes > 8388608) {
        throw new RuntimeException('Cleanup output exceeded 8 MiB.');
    }

    return '';
}, 8192);

try {
    $input = json_decode((string) stream_get_contents(STDIN, 8388609), true, 64, JSON_THROW_ON_ERROR);

    if (! is_array($input) || ! is_string($input['autoload'] ?? null) || ! is_file($input['autoload'])) {
        throw new RuntimeException('Invalid cleanup input.');
    }
    require_once $input['autoload'];
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Korioinc\\SchemaAlign\\';

        if (str_starts_with($class, $prefix)) {
            $name = substr($class, strlen($prefix));

            if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\\\\[A-Za-z][A-Za-z0-9_]*)*\z/D', $name)) {
                $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', $name).'.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }
        }
    }, true, true);
    foreach (['connection', 'binding', 'identity', 'schemas'] as $key) {
        if (! is_array($input[$key] ?? null)) {
            throw new RuntimeException('Invalid cleanup contract.');
        }
    }
    $pdo = TargetConnection::writable($input['connection'], $input['binding']['instance']);
    $identity = $input['identity'];

    $mode = $input['mode'] ?? 'exact_run';

    if (! in_array($mode, ['exact_run', 'owned_runs'], true) || ($mode === 'owned_runs' && $identity === [])) {
        throw new RuntimeException('Invalid cleanup mode or missing original identity.');
    }

    if ($identity === []) {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SELECT current_database() AS database, current_user AS current_user, inet_server_addr()::text AS server_addr, inet_server_port() AS server_port, d.oid::text AS database_oid, version() AS version FROM pg_catalog.pg_database d WHERE d.datname=current_database()'
            : 'SELECT @@server_uuid AS server_uuid, DATABASE() AS `database`, CURRENT_USER() AS `current_user`, USER() AS session_user, @@hostname AS host, @@port AS port, VERSION() AS version, @@version_comment AS product';
        $statement = $pdo->query($sql);
        $identity = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($identity)) {
            throw new RuntimeException('Cleanup server identity is unavailable.');
        }
    }
    $manager = new ReplayNamespace($pdo, $input['binding'], $identity, $input['schemas']);

    try {
        $result = $mode === 'owned_runs' ? $manager->cleanupOwned() : $manager->cleanup($input['run']);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } finally {
        $manager->release();
    }
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $message = $error->getMessage();
    foreach ($input['connection'] ?? [] as $key => $value) {
        if (is_string($value) && $value !== '' && preg_match('/password|secret|token|url/i', (string) $key)) {
            $message = str_replace($value, '[redacted]', $message);
        }
    }
    fwrite(STDERR, 'Cleanup refused or unconfirmed: '.substr($message, -4096)."\n");
    exit(1);
}
