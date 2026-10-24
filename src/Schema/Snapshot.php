<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Schema;

use InvalidArgumentException;

final class Snapshot
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $platform
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<array-key, mixed>  $unsupported
     * @param  list<string>  $migrationNames
     */
    public function __construct(
        public array $identity,
        public array $platform,
        public array $tables,
        public array $unsupported = [],
        public array $migrationNames = [],
    ) {
        $this->identity = self::sort($identity);
        $this->platform = self::sort($platform);
        $tables = array_map(fn (array $table): array => self::sort($table), self::sortIdentifierMaps($tables));
        ksort($tables, SORT_STRING);
        $this->tables = $tables;
        $this->unsupported = self::sort($unsupported);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::sort([
            'identity' => $this->identity,
            'platform' => $this->platform,
            'tables' => $this->tables,
            'unsupported' => $this->unsupported,
            'migrationNames' => $this->migrationNames,
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['identity', 'platform', 'tables', 'unsupported', 'migrationNames'] as $key) {
            if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
                throw new InvalidArgumentException("Invalid snapshot field: {$key}.");
            }
        }

        if (array_diff(array_keys($data), ['identity', 'platform', 'tables', 'unsupported', 'migrationNames']) !== []) {
            throw new InvalidArgumentException('Unknown snapshot fields.');
        }
        foreach (['identity', 'platform', 'tables'] as $key) {
            self::assertMap($data[$key], $key);
        }

        if (! array_is_list($data['migrationNames'])) {
            throw new InvalidArgumentException('Migration names must be a list.');
        }
        foreach ($data['migrationNames'] as $name) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('Invalid migration name.');
            }
        }
        foreach ($data['tables'] as $key => $table) {
            if (! is_array($table) || ! is_string($table['schema'] ?? null) || ! is_string($table['name'] ?? null)
                || $key !== $table['schema'].'.'.$table['name']) {
                throw new InvalidArgumentException('Invalid snapshot table.');
            }
            foreach (['columns', 'constraints', 'indexes'] as $part) {
                if (! is_array($table[$part] ?? null)) {
                    throw new InvalidArgumentException("Invalid table {$part}.");
                }

                // PHP converts canonical numeric SQL identifiers to integer array keys.
                if (array_key_exists('', $table[$part])) {
                    throw new InvalidArgumentException("Invalid {$part} map key.");
                }
            }
            foreach ($table['columns'] as $column) {
                if (! is_array($column) || ! is_string($column['type'] ?? null) || ! is_bool($column['nullable'] ?? null)
                    || ! array_key_exists('default', $column) || ! self::nullableString($column['default'])
                    || ! in_array($column['identity'] ?? null, ['', 'a', 'd'], true)
                    || ! in_array($column['generated'] ?? null, ['', 's', 'v'], true)
                    || ! array_key_exists('collation', $column) || ! self::nullableString($column['collation'])) {
                    throw new InvalidArgumentException('Invalid snapshot column.');
                }
            }
            foreach ($table['constraints'] as $constraint) {
                if (! is_array($constraint) || ! in_array($constraint['kind'] ?? null, ['p', 'u', 'f', 'c', 'n'], true)
                    || ! is_string($constraint['definition'] ?? null) || ! is_string($constraint['signature'] ?? null)
                    || ! is_array($constraint['columns'] ?? null) || ! array_is_list($constraint['columns'])
                    || ! array_key_exists('backing_index', $constraint) || ! self::nullableString($constraint['backing_index'])) {
                    throw new InvalidArgumentException('Invalid snapshot constraint.');
                }
                foreach ($constraint['columns'] as $column) {
                    if (! is_string($column)) {
                        throw new InvalidArgumentException('Invalid constraint column.');
                    }
                }
            }
            foreach ($table['indexes'] as $index) {
                if (! is_array($index) || ! is_string($index['definition'] ?? null) || ! is_string($index['signature'] ?? null)
                    || ! is_string($index['method'] ?? null) || ! is_array($index['keys'] ?? null) || ! array_is_list($index['keys'])
                    || ! is_array($index['include'] ?? null) || ! array_is_list($index['include'])
                    || ! is_bool($index['unique'] ?? null) || ! is_bool($index['nulls_not_distinct'] ?? null)
                    || ! array_key_exists('predicate', $index) || ! self::nullableString($index['predicate'])) {
                    throw new InvalidArgumentException('Invalid snapshot index.');
                }
            }
        }
        // Reject non-JSON values even in extensible metadata fields.
        self::canonical($data);

        return new self($data['identity'], $data['platform'], $data['tables'], $data['unsupported'], $data['migrationNames']);
    }

    public function fingerprint(): string
    {
        return self::fingerprintOf($this->platform, $this->tables, $this->unsupported);
    }

    /** @internal Hash a locally projected structure without constructing and sorting another snapshot.
     * @param  array<string, mixed>  $platform
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<array-key, mixed>  $unsupported
     */
    public static function fingerprintOf(array $platform, array $tables, array $unsupported): string
    {
        return hash('sha256', self::canonical([
            'platform' => $platform,
            'tables' => self::sortIdentifierMaps($tables),
            'unsupported' => $unsupported,
        ]));
    }

    public static function canonical(mixed $value): string
    {
        self::assertJson($value);

        return json_encode(is_array($value) ? self::sort($value) : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function assertJson(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertJson($item);
            }
        } elseif (! is_null($value) && ! is_scalar($value)) {
            throw new InvalidArgumentException('Snapshot values must be JSON data.');
        }
    }

    private static function nullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    /** @param array<array-key, mixed> $value */
    private static function assertMap(array $value, string $field): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException("Invalid {$field} map key.");
            }
        }
    }

    /** @param array<string, array<string, mixed>> $tables
     * @return array<string, array<string, mixed>>
     */
    private static function sortIdentifierMaps(array $tables): array
    {
        foreach ($tables as &$table) {
            foreach (['columns', 'constraints', 'indexes'] as $part) {
                if (isset($table[$part]) && is_array($table[$part])) {
                    // Even 0..n identifier keys form a map, not an ordered list.
                    ksort($table[$part], SORT_STRING);
                }
            }
        }
        unset($table);

        return $tables;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $value
     * @return array<TKey, mixed>
     */
    private static function sort(array $value): array
    {
        $list = array_is_list($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sort($item);
            }
        }
        unset($item);

        if (! $list) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
