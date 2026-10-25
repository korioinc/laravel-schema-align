<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Planning;

use Korioinc\SchemaAlign\Schema\Snapshot;
use RuntimeException;

/**
 * Shared comparator output. Engine comparators own eligibility and SQL; this class owns its structural effect.
 * Unsupported differences have no SQL. Column drops have SQL but require separate execution permission.
 * Executable operations add before_hash/after_hash in dependency order.
 * IDs bind kind, target, before/after and SQL, not the runtime checkpoint or selection order.
 *
 * @phpstan-type Definition array{
 *     id: string, kind: string, table: string, name: string,
 *     before: array<string, mixed>|null, after: array<string, mixed>|null,
 *     sql: list<string>, automatic: bool, reason: string|null, transactional: bool,
 *     dependencies: list<string>, old_name?: string, before_hash?: string, after_hash?: string
 * }
 */
final class Operation
{
    /** @param Definition $operation */
    public static function isExecutable(array $operation): bool
    {
        return $operation['sql'] !== [] && ($operation['automatic']
            || ($operation['kind'] === 'drop_column' && is_array($operation['before']) && $operation['after'] === null));
    }

    /** @param array<array-key, mixed> $operations */
    public static function hasColumnDrops(array $operations): bool
    {
        foreach ($operations as $operation) {
            if (is_array($operation) && ($operation['kind'] ?? null) === 'drop_column') {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $operations */
    public static function requireColumnDropPermission(array $operations, bool $allowDropColumns): void
    {
        if (! $allowDropColumns && self::hasColumnDrops($operations)) {
            throw new RuntimeException('Column removal deletes stored values. Re-run apply with --allow-drop-columns to permit the reviewed removals.');
        }
    }

    /**
     * Project only verified transformations, without executing SQL or granting execution permission.
     * A new automatic kind must define its effect here and prove comparison -> projection -> real engine apply.
     * Renames move one object; they do not alter the constraint/index definition supplied by the comparator.
     *
     * @param  Definition  $operation
     */
    public static function project(Snapshot $before, array $operation): Snapshot
    {
        $tables = $before->tables;
        self::apply($tables, $operation);

        return new Snapshot($before->identity, $before->platform, $tables, $before->unsupported, $before->migrationNames);
    }

    /** @internal Project an owned operation sequence; keep every signed checkpoint without repeated snapshot construction.
     * @param  list<Definition>  $operations
     * @return array{operations: list<Definition>, before_hash: string, after_hash: string}
     */
    public static function withCheckpoints(Snapshot $before, array $operations): array
    {
        $tables = $before->tables;
        $initialHash = $hash = $before->fingerprint();
        foreach ($operations as &$operation) {
            $operation['before_hash'] = $hash;
            self::apply($tables, $operation);
            $hash = Snapshot::fingerprintOf($before->platform, $tables, $before->unsupported);
            $operation['after_hash'] = $hash;
        }
        unset($operation);

        return ['operations' => $operations, 'before_hash' => $initialHash, 'after_hash' => $hash];
    }

    /** @param array<string, array<string, mixed>> $tables
     * @param  Definition  $operation
     */
    private static function apply(array &$tables, array $operation): void
    {
        $key = $operation['table'];
        $kind = $operation['kind'];
        $after = $operation['after'];

        if ($kind === 'drop_column') {
            if (! self::isExecutable($operation) || $operation['automatic']
                || ! isset($tables[$key]['columns'][$operation['name']])
                || Snapshot::canonical($tables[$key]['columns'][$operation['name']]) !== Snapshot::canonical($operation['before'])) {
                throw new RuntimeException('Column removal does not match the inspected original column.');
            }
            unset($tables[$key]['columns'][$operation['name']]);

            return;
        }

        if (! $operation['automatic'] || $operation['sql'] === [] || ! is_array($after)) {
            throw new RuntimeException('No verified postcondition model for operation '.$kind);
        }

        if ($kind === 'create_table') {
            $tables[$key] = $after;
        } elseif ($kind === 'add_column') {
            $tables[$key]['columns'][$operation['name']] = $after;
        } elseif ($kind === 'create_index') {
            $tables[$key]['indexes'][$operation['name']] = $after;
        } elseif ($kind === 'add_constraint') {
            $tables[$key]['constraints'][$operation['name']] = $after;
        } elseif (in_array($kind, ['rename_index', 'rename_constraint'], true)) {
            if (! is_string($operation['old_name'] ?? null) || $operation['old_name'] === '') {
                throw new RuntimeException('Rename operations require an original object name.');
            }
            $group = $kind === 'rename_index' ? 'indexes' : 'constraints';
            unset($tables[$key][$group][$operation['old_name']]);
            $tables[$key][$group][$operation['name']] = $after;
        } else {
            throw new RuntimeException('No verified postcondition model for operation '.$kind);
        }
    }
}
