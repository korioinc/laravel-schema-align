<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay\Schema;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;

/** Maps only default-name generation; SQL references and explicit names stay intact. */
final class ReplayBlueprint extends Blueprint
{
    public static function fromContainer(Connection $connection, string $table, ?Closure $callback = null): self
    {
        $container = Container::getInstance();
        self::assertSupportedContainer($container);
        $blueprint = $container->make(self::class, ['connection' => $connection, 'table' => $table, 'callback' => $callback]);
        self::assertSupportedContainer($container);

        return $blueprint;
    }

    private static function assertSupportedContainer(Container $container): void
    {
        if ($container->bound(Blueprint::class) || $container->bound(self::class)) {
            throw new RuntimeException('unsupported_blueprint_binding: replay cannot preserve a container-bound Blueprint implementation.');
        }

        // Resolving callbacks match parent types. Before callbacks and extenders
        // depend on the requested abstract, which must change for logical names.
        foreach (self::containerCallbacks($container, 'beforeResolvingCallbacks') as $type => $callbacks) {
            if ($callbacks !== [] && is_a(self::class, $type, true)) {
                throw new RuntimeException('unsupported_blueprint_construction: replay cannot preserve beforeResolving Blueprint hooks.');
            }
        }

        if (self::containerCallbacks($container, 'globalBeforeResolvingCallbacks') !== []) {
            throw new RuntimeException('unsupported_blueprint_construction: replay cannot preserve global beforeResolving hooks.');
        }
        foreach (self::containerCallbacks($container, 'extenders') as $type => $callbacks) {
            if ($callbacks !== [] && in_array($container->getAlias($type), [Blueprint::class, self::class], true)) {
                throw new RuntimeException('unsupported_blueprint_construction: replay cannot preserve Blueprint extenders.');
            }
        }
        foreach (self::containerCallbacks($container, 'contextual') as $bindings) {
            if (! is_array($bindings)) {
                throw new RuntimeException('unsupported_blueprint_construction: invalid contextual binding metadata.');
            }
            foreach (array_keys($bindings) as $type) {
                if (in_array($container->getAlias($type), [Blueprint::class, self::class], true)) {
                    throw new RuntimeException('unsupported_blueprint_construction: replay cannot preserve contextual Blueprint bindings.');
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private static function containerCallbacks(Container $container, string $property): array
    {
        // Laravel exposes registration but no inspection API for these hooks.
        // Read only: rejecting unsupported hooks must not mutate the host app.
        try {
            $callbacks = (new ReflectionProperty(Container::class, $property))->getValue($container);
        } catch (ReflectionException $error) {
            throw new RuntimeException('unsupported_blueprint_construction: container hooks cannot be inspected.', 0, $error);
        }

        if (! is_array($callbacks)) {
            throw new RuntimeException('unsupported_blueprint_construction: invalid container hook metadata.');
        }

        return $callbacks;
    }

    /** @param string $type
     * @param  array<string>  $columns
     */
    protected function createIndexName($type, array $columns): string
    {
        $physical = $this->table;
        $mapping = $this->connection->getConfig('schema_align_schema_map') ?? [];

        if (! is_array($mapping)) {
            throw new RuntimeException('Invalid replay naming context.');
        }
        foreach ($mapping as $logical => $schema) {
            if (is_string($schema) && str_starts_with($physical, $schema.'.')) {
                $this->table = $logical.substr($physical, strlen($schema));

                break;
            }
        }

        try {
            return parent::createIndexName($type, $columns);
        } finally {
            $this->table = $physical;
        }
    }
}
