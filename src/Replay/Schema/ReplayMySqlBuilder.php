<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay\Schema;

use Closure;
use Illuminate\Database\Schema\MySqlBuilder;
use RuntimeException;

/** Every builder instance enforces logical default object names. */
final class ReplayMySqlBuilder extends MySqlBuilder
{
    protected function createBlueprint($table, ?Closure $callback = null): ReplayBlueprint
    {
        if (isset($this->resolver)) {
            throw new RuntimeException('unsupported_blueprint_resolver: replay requires logical default names.');
        }

        return ReplayBlueprint::fromContainer($this->connection, $table, $callback);
    }
}
