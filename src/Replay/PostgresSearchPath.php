<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use Illuminate\Database\Concerns\ParsesSearchPath;
use RuntimeException;

final class PostgresSearchPath
{
    use ParsesSearchPath;

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    public function map(array $config, array $mapping): array
    {
        $path = $config['search_path'] ?? $config['schema'] ?? null;

        if (! is_string($path) && ! is_array($path)) {
            throw new RuntimeException('unsupported_replay_search_path: configure an explicit PostgreSQL search_path or schema.');
        }
        foreach (is_array($path) ? $path : [] as $schema) {
            if (! is_string($schema)) {
                throw new RuntimeException('unsupported_replay_search_path: PostgreSQL search_path entries must be strings.');
            }
        }
        $schemas = $this->parseSearchPath($path);

        if ($schemas === []) {
            throw new RuntimeException('unsupported_replay_search_path: PostgreSQL search_path must not be empty.');
        }
        $mapped = [];
        foreach ($schemas as $schema) {
            if ($schema === '$user' || ! isset($mapping[$schema])) {
                throw new RuntimeException('unsupported_replay_search_path: each schema must be explicit and inside the configured comparison scope: '.$schema);
            }
            $mapped[] = $mapping[$schema];
        }

        return $mapped;
    }
}
