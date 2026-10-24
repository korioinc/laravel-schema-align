<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\PostgresBuilder;
use RuntimeException;

/**
 * Every builder instance enforces logical default object names.
 * Early Laravel 13 releases omit null from the inherited resolver's PHPDoc.
 *
 * @property (Closure(Connection, string, Closure|null): Blueprint)|null $resolver
 */
final class ReplayPostgresBuilder extends PostgresBuilder
{
    /** @param string $table */
    protected function createBlueprint(mixed $table, ?Closure $callback = null): ReplayBlueprint
    {
        if (isset($this->resolver)) {
            throw new RuntimeException('unsupported_blueprint_resolver: replay requires logical default names.');
        }

        return ReplayBlueprint::fromContainer($this->connection, $table, $callback);
    }
}
