<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\ConfigurationUrlParser;
use PDO;
use RuntimeException;

final class TargetConnection
{
    private const array MYSQL_INSPECTION_PRIVILEGES = ['USAGE', 'SHOW_ROUTINE', 'SELECT', 'SHOW VIEW', 'PROCESS', 'SHOW DATABASES'];

    /** @param array<string, mixed> $config */
    public static function connect(array $config, string $instance): PDO
    {
        $pdo = self::readOnly($config, $instance);
        self::requireRestrictedRole($pdo);

        return $pdo;
    }

    /** @param array<string, mixed> $config */
    public static function readOnly(array $config, string $instance): PDO
    {
        $pdo = self::writable($config, $instance);
        $pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SET default_transaction_read_only = on' : 'SET SESSION transaction_read_only = ON');

        return $pdo;
    }

    /** A separate session for owned replay resources, never an apply authorization bypass.
     * @param  array<string, mixed>  $config
     */
    public static function writable(array $config, string $instance): PDO
    {
        if ($instance === '') {
            throw new RuntimeException('Configure an explicit target_instance identifier before connecting.');
        }
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);

        $driver = $config['driver'] ?? null;

        if (! in_array($driver, ['pgsql', 'mysql'], true) || isset($config['read']) || isset($config['write'])) {
            throw new RuntimeException('A single pgsql or mysql connection is required; read/write routing is unsupported.');
        }
        foreach (['host', 'database', 'username'] as $key) {
            if ($key === 'host' && $driver === 'mysql' && is_string($config['unix_socket'] ?? null) && $config['unix_socket'] !== '') {
                continue;
            }

            if (! is_string($config[$key] ?? null) || $config[$key] === '' || preg_match('/[;\x00-\x1f]/', $config[$key])) {
                throw new RuntimeException('Invalid resolved database connection '.$key.'.');
            }
        }

        if (isset($config['port']) && (! is_numeric($config['port']) || (int) $config['port'] < 1 || (int) $config['port'] > 65535)) {
            throw new RuntimeException('Invalid resolved database port.');
        }

        // Package session settings must never reach a persistent consumer connection.
        $config['options'] = array_replace($config['options'] ?? [], [PDO::ATTR_PERSISTENT => false]);

        if ($driver === 'mysql') {
            $config['options'] = array_replace($config['options'], [PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
        }
        $pdo = ($driver === 'pgsql' ? new PostgresConnector : new MySqlConnector)->connect($config);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($driver === 'pgsql' ? 'SET default_transaction_read_only = off' : 'SET SESSION transaction_read_only = OFF');

        if ($driver === 'mysql') {
            $query = $pdo->query('SELECT VERSION()');

            if ($query === false) {
                throw new RuntimeException('Cannot verify MySQL server version.');
            }
            $version = $query->fetchColumn();

            if (! is_string($version) || stripos($version, 'mariadb') !== false || ! preg_match('/\A8\.(?:0|4)\./', $version) || version_compare($version, '8.0.20', '<')) {
                throw new RuntimeException('MySQL 8.0.20+ or 8.4 is required. MariaDB and unverified server families are unsupported.');
            }
        }

        return $pdo;
    }

    /** @return list<string> */
    public static function warnings(PDO $pdo): array
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        return self::isSuperuser($pdo)
            ? [$mysql ? 'MySQL global write, administrative or delegation privileges detected. Review the SQL carefully; approved apply is allowed.' : 'PostgreSQL superuser privileges detected. Review the SQL carefully; approved apply is allowed.']
            : [];
    }

    /** Legacy name: validate MySQL grant evidence, never restrict the account's privilege level. */
    public static function requireRestrictedRole(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            self::mysqlGlobalPrivileges($pdo);
        }
    }

    private static function isSuperuser(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $grants = self::mysqlGrants($pdo);
            $privileges = self::globalPrivileges($grants);
            foreach ($grants as $grant) {
                if (preg_match('/\bWITH\s+GRANT\s+OPTION\z/i', $grant)) {
                    return true;
                }
            }

            return array_diff($privileges, self::MYSQL_INSPECTION_PRIVILEGES) !== [];
        }
        $query = $pdo->query(<<<'SQL'
SELECT r.rolsuper::int AS current_superuser, s.rolsuper::int AS session_superuser
FROM pg_catalog.pg_roles r CROSS JOIN pg_catalog.pg_roles s
WHERE r.rolname = current_user AND s.rolname = session_user
SQL);

        if ($query === false) {
            throw new RuntimeException('Cannot verify target role.');
        }
        $roles = $query->fetchAll(PDO::FETCH_ASSOC);

        if (count($roles) !== 1 || ! isset($roles[0]['current_superuser'], $roles[0]['session_superuser'])
            || ! in_array((string) $roles[0]['current_superuser'], ['0', '1'], true)
            || ! in_array((string) $roles[0]['session_superuser'], ['0', '1'], true)) {
            throw new RuntimeException('Cannot verify current and session target roles.');
        }

        return (int) $roles[0]['current_superuser'] === 1 || (int) $roles[0]['session_superuser'] === 1;
    }

    /** Exact effective global tokens; account identifiers never contribute privileges.
     * @return list<string>
     */
    public static function mysqlGlobalPrivileges(PDO $pdo): array
    {
        return self::globalPrivileges(self::mysqlGrants($pdo));
    }

    /** @param list<string> $grants
     * @return list<string>
     */
    private static function globalPrivileges(array $grants): array
    {
        if (count($grants) > 1024) {
            throw new RuntimeException('MySQL grant evidence exceeds the supported bound.');
        }
        $privileges = [];
        $pattern = <<<'REGEX'
~\AGRANT (?<privileges>[A-Z_ ,]+) ON (?<scope>\*\.\*|`(?:``|[^`])+`\.(?:\*|`(?:``|[^`])+`)) TO (?:`(?:``|[^`])*`|'(?:''|[^'])*')@(?:`(?:``|[^`])*`|'(?:''|[^'])*')(?: WITH GRANT OPTION)?\z~iD
REGEX;
        foreach ($grants as $grant) {
            if (strlen($grant) > 65536 || ! preg_match($pattern, $grant, $match)) {
                throw new RuntimeException('Unsupported MySQL grant evidence, including partial revokes, proxy grants or role assignments.');
            }

            if ($match['scope'] !== '*.*') {
                continue;
            }
            foreach (explode(',', $match['privileges']) as $privilege) {
                $privilege = strtoupper(trim($privilege));

                if ($privilege === '' || ! preg_match('/\A[A-Z_]+(?: [A-Z_]+)*\z/D', $privilege)) {
                    throw new RuntimeException('Malformed MySQL global privilege evidence.');
                }
                $privileges[] = $privilege;
            }
        }

        return array_values(array_unique($privileges));
    }

    /** @return list<string> */
    public static function mysqlGrants(PDO $pdo): array
    {
        $roles = $pdo->query('SELECT CURRENT_ROLE()');

        if ($roles === false || $roles->fetchColumn() !== 'NONE') {
            throw new RuntimeException('MySQL active role grants are not yet supported; use an explicit database-scoped login.');
        }
        $query = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');

        if ($query === false) {
            throw new RuntimeException('Cannot verify MySQL target privileges.');
        }
        $grants = $query->fetchAll(PDO::FETCH_COLUMN);

        if ($grants === []) {
            throw new RuntimeException('Missing MySQL privilege evidence.');
        }
        foreach ($grants as $grant) {
            if (! is_string($grant)) {
                throw new RuntimeException('Malformed MySQL privilege evidence.');
            }
        }

        return array_values($grants);
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function binding(array $config, string $instance, string $environment): array
    {
        $resolved = (new ConfigurationUrlParser)->parseConfiguration($config);

        $binding = [
            'instance' => $instance,
            'environment' => $environment,
            'host' => $resolved['host'] ?? null,
            'port' => (string) ($resolved['port'] ?? 5432),
            'database' => $resolved['database'] ?? null,
            'username' => $resolved['username'] ?? null,
            'sslmode' => $resolved['sslmode'] ?? null,
        ];

        if (($resolved['driver'] ?? null) === 'mysql') {
            $binding['driver'] = 'mysql';
            $binding['port'] = (string) ($resolved['port'] ?? 3306);
            $binding['unix_socket'] = $resolved['unix_socket'] ?? null;
            unset($binding['sslmode']);
        }

        return $binding;
    }
}
