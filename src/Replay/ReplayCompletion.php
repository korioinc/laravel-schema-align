<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use RuntimeException;

/** Completion contract between the current PHP worker and its coordinator. */
final class ReplayCompletion
{
    public const int VERSION = 2;

    public const string NAMING_STRATEGY = 'logical-blueprint-v1';

    /** @param list<string> $names
     * @return array{version: int, naming_strategy: string, migration_digest: string, migration_names: list<string>}
     */
    public static function complete(string $digest, array $names): array
    {
        return ['version' => self::VERSION, 'naming_strategy' => self::NAMING_STRATEGY,
            'migration_digest' => $digest, 'migration_names' => $names];
    }

    /** @param array<string, string> $manifest
     * @return array{version: int, naming_strategy: string, migration_digest: string, migration_names: list<string>}
     */
    public static function validate(mixed $payload, array $manifest, string $expectedDigest): array
    {
        if (! is_array($payload) || ($payload['version'] ?? null) !== self::VERSION
            || ($payload['naming_strategy'] ?? null) !== self::NAMING_STRATEGY
            || ! is_string($payload['migration_digest'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $payload['migration_digest'])
            || $payload['migration_digest'] !== $expectedDigest
            || ! is_array($payload['migration_names'] ?? null) || ! array_is_list($payload['migration_names'])) {
            throw new RuntimeException('Invalid local replay completion evidence.');
        }
        $names = $payload['migration_names'];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Invalid local replay migration name.');
            }
        }
        $expectedNames = [];
        foreach (array_keys($manifest) as $path) {
            if (! str_ends_with($path, '/')) {
                $expectedNames[] = pathinfo($path, PATHINFO_FILENAME);
            }
        }
        sort($expectedNames, SORT_STRING);

        if (count(array_unique($expectedNames, SORT_STRING)) !== count($expectedNames)
            || $names !== $expectedNames) {
            throw new RuntimeException('Replay completion differs from the complete migration manifest.');
        }

        return self::complete($payload['migration_digest'], $names);
    }

    /** Length-prefixed records bind the source revision, lock bytes and sorted migration manifest.
     * @param  array<string, string>  $manifest
     */
    public static function digest(string $revision, string $lock, array $manifest): string
    {
        ksort($manifest, SORT_STRING);
        $hash = hash_init('sha256');
        $feed = static function (string $value) use ($hash): void {
            hash_update($hash, strlen($value).':'.$value);
        };
        $feed($revision);
        $feed($lock);
        foreach ($manifest as $path => $digest) {
            $feed($path);
            $feed($digest);
        }

        return hash_final($hash);
    }
}
