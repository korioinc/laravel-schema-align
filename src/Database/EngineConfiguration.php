<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Database;

use InvalidArgumentException;

final class EngineConfiguration
{
    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function resolve(array $config, string $driver): array
    {
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new InvalidArgumentException('Schema Align supports pgsql and mysql connections only.');
        }

        if (($config['driver'] ?? null) !== null && $config['driver'] !== $driver) {
            throw new InvalidArgumentException('schema-align.driver differs from the resolved application connection driver.');
        }
        $defaults = require dirname(__DIR__, 2).'/config/schema-align.php';
        $engine = $config['engines'][$driver] ?? [];

        if (! is_array($engine)) {
            throw new InvalidArgumentException('schema-align.engines.'.$driver.' must be an array.');
        }
        // Engine options are complete values; numeric schema lists must never merge by index.
        $engine = array_replace($defaults['engines'][$driver], $engine);

        if ($driver === 'pgsql') {
            $engine['schemas'] = self::normalizeSchemas($engine['schemas'], $driver);

            if (array_key_exists('schemas', $config)) {
                $config['schemas'] = self::normalizeSchemas($config['schemas'], $driver);
            }
        }
        $legacyKeys = ['schemas', 'migration_table', 'lock_timeout_ms', 'statement_timeout_ms'];
        foreach ($legacyKeys as $key) {
            if (! array_key_exists($key, $config)) {
                continue;
            }

            if ($driver !== 'pgsql') {
                throw new InvalidArgumentException('Legacy PostgreSQL setting schema-align.'.$key.' must be moved to engines.pgsql before using mysql.');
            }

            if ($engine[$key] !== $defaults['engines']['pgsql'][$key]
                && $engine[$key] !== $config[$key]) {
                throw new InvalidArgumentException('Conflicting schema-align.'.$key.' and engines.pgsql.'.$key.' settings.');
            }
            $engine[$key] = $config[$key];
        }
        $sharedReplay = $config['replay'] ?? [];

        if (! is_array($sharedReplay)) {
            throw new InvalidArgumentException('schema-align.replay must be an array.');
        }
        foreach (['postgres_image', 'postgres_args', 'extensions', 'schemas'] as $key) {
            if (! array_key_exists($key, $sharedReplay)) {
                continue;
            }

            if ($driver !== 'pgsql') {
                // Previously published PostgreSQL defaults have no effect on a MySQL connection.
                if (! in_array($sharedReplay[$key], [null, [], ['public']], true)) {
                    throw new InvalidArgumentException('PostgreSQL replay setting '.$key.' must be moved to engines.pgsql.replay.');
                }
                unset($sharedReplay[$key]);

                continue;
            }

            if ($key === 'schemas') {
                $schemas = self::normalizeSchemas($sharedReplay[$key], $driver);

                // Published replay public is a legacy default. Merged engine defaults
                // likewise cannot be distinguished from an explicitly supplied default.
                if ($schemas !== $defaults['engines']['pgsql']['schemas']) {
                    if (($engine['schemas'] !== $defaults['engines']['pgsql']['schemas'] || array_key_exists('schemas', $config))
                        && $engine['schemas'] !== $schemas) {
                        throw new InvalidArgumentException('Conflicting PostgreSQL replay setting schemas.');
                    }
                    $engine['schemas'] = $schemas;
                }
            } else {
                $current = $engine['replay'][$key] ?? null;
                $default = $defaults['engines']['pgsql']['replay'][$key] ?? null;

                if ($current !== $default && $current !== $sharedReplay[$key]) {
                    throw new InvalidArgumentException('Conflicting PostgreSQL replay setting '.$key.'.');
                }
                $engine['replay'][$key] = $sharedReplay[$key];
            }
            unset($sharedReplay[$key]);
        }
        $engine['replay'] ??= [];
        $config['driver'] = $driver;
        $config['schemas'] = $driver === 'pgsql' ? $engine['schemas'] : ['@database'];
        $config['migration_table'] = $engine['migration_table'];
        $config['lock_timeout_ms'] = $engine['lock_timeout_ms'];
        $config['statement_timeout_ms'] = $driver === 'pgsql' ? $engine['statement_timeout_ms'] : 0;

        if (! is_int($config['statement_timeout_ms']) || $config['statement_timeout_ms'] < 0 || $config['statement_timeout_ms'] > 2147483647) {
            throw new InvalidArgumentException('schema-align.engines.pgsql.statement_timeout_ms must be an integer between 0 and 2147483647; 0 disables the execution limit.');
        }
        $config['replay'] = array_replace($defaults['replay'], $sharedReplay, $engine['replay']);
        $obsolete = ['runtime_image' => null, 'build_timeout' => 600, 'image' => null,
            'revision' => null, 'trusted_image' => false, 'postgres_image' => null,
            'postgres_args' => [], 'extensions' => [], 'mysql_image' => null, 'mysql_args' => []];
        foreach ($obsolete as $key => $default) {
            if (array_key_exists($key, $config['replay']) && $config['replay'][$key] !== $default) {
                throw new InvalidArgumentException('Obsolete Docker setting schema-align.replay.'.$key.' must be removed before namespace replay.');
            }
            unset($config['replay'][$key]);
        }

        if (! is_int($config['replay']['timeout']) || $config['replay']['timeout'] < 1 || $config['replay']['timeout'] > 3600) {
            throw new InvalidArgumentException('schema-align.replay.timeout must be an integer between 1 and 3600.');
        }

        if (! is_array($config['replay']['migration_paths']) || ! array_is_list($config['replay']['migration_paths'])) {
            throw new InvalidArgumentException('schema-align.replay.migration_paths must be a list.');
        }

        $config['schemas'] = self::normalizeSchemas($config['schemas'], $driver);

        if (! is_string($config['migration_table']) || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $config['migration_table'])
            || strlen($config['migration_table']) > 63 || $config['migration_table'] === '__schema_align_run') {
            throw new InvalidArgumentException('Invalid migration table identifier.');
        }
        $config['replay']['driver'] = $driver;

        return $config;
    }

    /** @param array<string, mixed> $connection Resolved Laravel connection configuration. */
    public static function physicalMigrationTable(array $connection, string $logical): string
    {
        $driver = $connection['driver'] ?? null;
        $prefix = $connection['prefix'] ?? '';

        if (! in_array($driver, ['pgsql', 'mysql'], true) || ! is_string($prefix)) {
            throw new InvalidArgumentException('Invalid migration table connection prefix or driver.');
        }
        $physical = $prefix.$logical;
        $length = $driver === 'pgsql' ? strlen($physical) : mb_strlen($physical, 'UTF-8');

        if ($logical === '' || str_contains($physical, "\0") || preg_match('//u', $physical) !== 1
            || $length > ($driver === 'pgsql' ? 63 : 64)
            || ($driver === 'mysql' ? strtolower($physical) : $physical) === '__schema_align_run') {
            throw new InvalidArgumentException('Invalid physical migration table identifier after applying the connection prefix.');
        }

        return $physical;
    }

    /** @return list<string> */
    private static function normalizeSchemas(mixed $schemas, string $driver): array
    {
        if (! is_array($schemas) || ! array_is_list($schemas) || $schemas === []) {
            throw new InvalidArgumentException('At least one original schema is required.');
        }
        foreach ($schemas as $schema) {
            if (! is_string($schema) || $schema === '' || strlen($schema) > 63 || preg_match('/[.\x00-\x1f]/', $schema)
                || ($driver === 'pgsql' && (str_starts_with($schema, 'pg_') || $schema === 'information_schema' || preg_match('/\Aalign-[0-9]{4}-[a-f0-9]{5}\z/D', $schema)))) {
                throw new InvalidArgumentException('Invalid original schema scope.');
            }
        }

        if (count(array_unique($schemas)) !== count($schemas)) {
            throw new InvalidArgumentException('Duplicate original schema scope.');
        }
        sort($schemas, SORT_STRING);

        return $schemas;
    }
}
