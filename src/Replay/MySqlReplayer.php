<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use Korioinc\SchemaAlign\Schema\Snapshot;
use RuntimeException;

/** MySQL uses the shared local worker with its own catalog and owned database lifecycle. */
final class MySqlReplayer
{
    /** @param array<string, mixed> $config
     * @return array{snapshot: Snapshot, source: array<string, mixed>}
     */
    public function capture(array $config): array
    {
        if (($config['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('MySQL namespace replay requires a mysql connection.');
        }

        return (new ReleaseReplayer)->capture($config);
    }
}
