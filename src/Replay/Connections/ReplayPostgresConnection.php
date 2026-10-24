<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay\Connections;

use Closure;
use Illuminate\Database\PostgresConnection;
use Korioinc\SchemaAlign\Replay\Schema\ReplayPostgresBuilder;
use PDO;
use RuntimeException;

/** Used exclusively by the trusted replay worker. */
final class ReplayPostgresConnection extends PostgresConnection
{
    public function getSchemaBuilder(): ReplayPostgresBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new ReplayPostgresBuilder($this);
    }

    /** @param PDO|Closure|null $pdo */
    public function setPdo(mixed $pdo): static
    {
        if ($pdo !== null && $pdo !== $this->pdo) {
            throw new RuntimeException('Replacing a registered replay PDO is unsupported.');
        }
        parent::setPdo($pdo);

        return $this;
    }

    /** @param PDO|Closure|null $pdo */
    public function setReadPdo(mixed $pdo): static
    {
        if ($pdo !== null && $pdo !== $this->pdo) {
            throw new RuntimeException('Unregistered replay read sessions are unsupported.');
        }
        parent::setReadPdo($pdo);

        return $this;
    }

    /** @param PDO|Closure|null $pdo */
    public function setDirectPdo(mixed $pdo): static
    {
        if ($pdo !== null) {
            throw new RuntimeException('Unregistered replay direct sessions are unsupported.');
        }

        return $this;
    }

    public function setReconnector(callable $reconnector): static
    {
        parent::setReconnector(static function (): never {
            throw new RuntimeException('Replay reconnection is unsupported; every session must be registered.');
        });

        return $this;
    }
}
