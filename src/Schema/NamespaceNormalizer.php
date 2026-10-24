<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Schema;

use InvalidArgumentException;
use Korioinc\SchemaAlign\Schema\Catalog\MySqlCatalog;
use RuntimeException;

/** Converts catalog identifiers, never application strings or object names. */
final class NamespaceNormalizer
{
    /** @param array<array-key, string> $schemaMap Logical => physical namespace. */
    public static function normalize(Snapshot $snapshot, array $schemaMap): Snapshot
    {
        $reverse = [];
        foreach ($schemaMap as $logical => $physical) {
            $logical = self::mappingIdentifier((string) $logical);
            $physical = self::mappingIdentifier($physical);

            if (isset($reverse[$physical])) {
                throw new InvalidArgumentException('Namespace mapping must contain unique, nonempty identifiers.');
            }
            $reverse[$physical] = $logical;
        }

        if ($schemaMap === []) {
            return Snapshot::fromArray($snapshot->toArray());
        }
        $mysql = ($snapshot->platform['engine'] ?? null) === 'mysql';

        if ($mysql) {
            // MySqlCatalog already uses @database, including internal FK targets.
            return Snapshot::fromArray($snapshot->toArray());
        }
        $sqlMap = array_map(static fn (string $logical): string => self::quote($logical, (int) ($snapshot->platform['server_major'] ?? 18)), $reverse);
        $platform = $snapshot->platform;

        if (isset($platform['schemas'])) {
            $platform['schemas'] = array_map(static fn (string $schema): string => $reverse[$schema] ?? $schema, $platform['schemas']);
            sort($platform['schemas'], SORT_STRING);
        }
        foreach ($platform['extensions'] ?? [] as $name => $extension) {
            $platform['extensions'][$name]['schema'] = $reverse[$extension['schema']] ?? $extension['schema'];
        }

        if (isset($platform['collations'])) {
            $collations = [];
            foreach ($platform['collations'] as $collation) {
                $collation['nspname'] = $reverse[$collation['nspname']] ?? $collation['nspname'];
                $collations[$collation['nspname'].'.'.$collation['collname']] = $collation;
            }
            $platform['collations'] = $collations;
        }

        foreach (['sequences', 'excluded_relations'] as $collection) {
            if (! isset($platform[$collection])) {
                continue;
            }
            $relations = [];
            foreach ($platform[$collection] as $relation) {
                $relation['schema'] = $reverse[$relation['schema']] ?? $relation['schema'];
                $relations[$relation['schema'].'.'.$relation['name']] = $relation;
            }
            $platform[$collection] = $relations;
        }
        $tables = [];
        foreach ($snapshot->tables as $table) {
            $table['schema'] = $reverse[$table['schema']] ?? $table['schema'];
            foreach ($table['columns'] as &$column) {
                foreach (['type', 'collation', 'default', 'generation_expression'] as $field) {
                    if (is_string($column[$field] ?? null)) {
                        $column[$field] = self::sql($column[$field], $sqlMap);
                    }
                }
            }
            unset($column);
            foreach ($table['constraints'] as &$constraint) {
                if (is_string($constraint['foreign_schema'] ?? null)) {
                    $constraint['foreign_schema'] = $reverse[$constraint['foreign_schema']] ?? $constraint['foreign_schema'];
                }
                $constraint['definition'] = self::sql($constraint['definition'], $sqlMap);

                if (isset($constraint['index'])) {
                    $constraint['index'] = self::index($constraint['index'], $sqlMap);
                }
                $semantic = $constraint;
                unset($semantic['signature'], $semantic['backing_index']);
                $constraint['signature'] = Snapshot::canonical($semantic);
            }
            unset($constraint);
            foreach ($table['indexes'] as &$index) {
                $index = self::index($index, $sqlMap);
                $index['definition'] = self::sql($index['definition'], $sqlMap);
                $semantic = $index;
                unset($semantic['signature'], $semantic['definition']);
                $index['signature'] = Snapshot::canonical($semantic);
            }
            unset($index);
            $key = $table['schema'].'.'.$table['name'];

            if (isset($tables[$key])) {
                throw new InvalidArgumentException('Namespace mapping produces duplicate tables.');
            }
            $tables[$key] = $table;
        }

        // Unsupported snapshots cannot be compared. Do not rewrite their diagnostic text.
        if ($snapshot->unsupported !== []) {
            throw new RuntimeException('unsupported_namespace_expression: unsupported catalog objects cannot be normalized.');
        }

        return new Snapshot($snapshot->identity, $platform, $tables, [], $snapshot->migrationNames);
    }

    /** @param array<string, mixed> $index
     * @param  array<string, string>  $reverse
     * @return array<string, mixed>
     */
    private static function index(array $index, array $reverse): array
    {
        if (is_string($index['predicate'] ?? null)) {
            $index['predicate'] = self::sql($index['predicate'], $reverse);
        }
        foreach ($index['keys'] as &$key) {
            foreach (['definition', 'expression', 'opclass', 'collation'] as $field) {
                if (is_string($key[$field] ?? null)) {
                    $key[$field] = self::sql($key[$field], $reverse);
                }
            }
        }
        unset($key);

        return $index;
    }

    /** Tokenize PostgreSQL deparser output. Only namespace components and literal regclass identities change.
     * @param  array<string, string>  $reverse
     */
    private static function sql(string $sql, array $reverse): string
    {
        $tokens = self::tokens($sql);
        $significant = [];
        foreach ($tokens as $i => $token) {
            if (! in_array($token['kind'], ['space', 'comment'], true)) {
                $significant[] = $i;
            }
        }
        foreach ($significant as $position => $i) {
            $token = $tokens[$i];
            $next = $tokens[$significant[$position + 1] ?? -1]['text'] ?? '';

            if (($token['kind'] ?? null) === 'identifier' && $next === '('
                && in_array(self::identifier($token['text']), ['to_regclass', 'to_regtype', 'to_regnamespace', 'to_regproc', 'to_regprocedure', 'to_regoper', 'to_regoperator', 'to_regcollation'], true)) {
                throw new RuntimeException('unsupported_namespace_expression: dynamic namespace lookup has no catalog object identity.');
            }

            if (($token['kind'] ?? null) === 'identifier' && $next === '.') {
                $name = self::identifier($token['text']);

                if (isset($reverse[$name])) {
                    $tokens[$i]['text'] = $reverse[$name];
                }
            }

            if ($token['text'] !== '::') {
                continue;
            }
            $typePosition = $position + 1;
            $type = $tokens[$significant[$typePosition] ?? -1]['text'] ?? '';

            if ($type === 'pg_catalog' && ($tokens[$significant[$typePosition + 1] ?? -1]['text'] ?? '') === '.') {
                $typePosition += 2;
                $type = $tokens[$significant[$typePosition] ?? -1]['text'] ?? '';
            }
            $type = self::identifier($type);

            if (! str_starts_with($type, 'reg') || ! in_array($type, ['regclass', 'regtype', 'regnamespace', 'regproc', 'regprocedure', 'regoperator', 'regoper', 'regcollation'], true)) {
                continue;
            }
            $previous = $significant[$position - 1] ?? -1;

            if ($type !== 'regclass' || ($tokens[$previous]['kind'] ?? '') !== 'string') {
                throw new RuntimeException('unsupported_namespace_expression: only literal regclass identities are supported.');
            }
            $literal = $tokens[$previous]['text'];
            $identity = str_replace("''", "'", substr($literal, 1, -1));
            $parts = self::tokens($identity);

            if (count($parts) !== 3 || $parts[0]['kind'] !== 'identifier' || $parts[1]['text'] !== '.' || $parts[2]['kind'] !== 'identifier') {
                throw new RuntimeException('unsupported_namespace_expression: regclass identity must be schema-qualified.');
            }
            $namespace = self::identifier($parts[0]['text']);

            if (isset($reverse[$namespace])) {
                $identity = $reverse[$namespace].'.'.$parts[2]['text'];
                $tokens[$previous]['text'] = "'".str_replace("'", "''", $identity)."'";
            }
        }

        return implode('', array_column($tokens, 'text'));
    }

    /** @return list<array{kind: string, text: string}> */
    private static function tokens(string $sql): array
    {
        $tokens = [];
        $offset = 0;
        $pattern = <<<'REGEX'
~\G(?:(?<space>\s+)|(?<comment>--[^\n]*(?:\n|$)|(?<block>/\*(?:[^/*]+|/(?!\*)|\*(?!/)|(?&block))*\*/))|(?<escaped>[eE]'(?:[^'\\]|\\.|'')*')|(?<dollar>\$\$.*?\$\$|\$(?<tag>[a-zA-Z_][a-zA-Z_0-9]*)\$.*?\$\k<tag>\$)|(?<string>'(?:[^']|'')*')|(?<identifier>"(?:[^"]|"")*"|[a-zA-Z_\x80-\xff][a-zA-Z_0-9$\x80-\xff]*)|(?<cast>::)|(?<symbol>[^'"\s]))~s
REGEX;
        while ($offset < strlen($sql)) {
            if (preg_match($pattern, $sql, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                throw new RuntimeException('unsupported_namespace_expression: malformed SQL token.');
            }
            foreach (['space', 'comment', 'escaped', 'dollar', 'string', 'identifier', 'cast', 'symbol'] as $kind) {
                if ($match[$kind] !== null) {
                    $tokens[] = ['kind' => $kind, 'text' => $match[0]];

                    break;
                }
            }
            $offset += strlen($match[0]);
        }

        return $tokens;
    }

    private static function mappingIdentifier(mixed $identifier): string
    {
        if (! is_string($identifier) || $identifier === '' || str_contains($identifier, "\0") || str_contains($identifier, '.')) {
            throw new InvalidArgumentException('Namespace mapping must contain nonempty identifiers.');
        }

        return $identifier;
    }

    private static function identifier(string $token): string
    {
        return str_starts_with($token, '"') ? str_replace('""', '"', substr($token, 1, -1)) : strtolower($token);
    }

    private static function quote(string $identifier, int $major): string
    {
        // Match quote_ident keyword categories (PostgreSQL src/include/parser/kwlist.h, 15–18).
        $keywords = ['all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'asymmetric', 'authorization', 'between', 'bigint', 'binary', 'bit', 'boolean', 'both', 'case', 'cast', 'char', 'character', 'check', 'coalesce', 'collate', 'collation', 'column', 'concurrently', 'constraint', 'create', 'cross', 'current_catalog', 'current_date', 'current_role', 'current_schema', 'current_time', 'current_timestamp', 'current_user', 'dec', 'decimal', 'default', 'deferrable', 'desc', 'distinct', 'do', 'else', 'end', 'except', 'exists', 'extract', 'false', 'fetch', 'float', 'for', 'foreign', 'freeze', 'from', 'full', 'grant', 'greatest', 'group', 'grouping', 'having', 'ilike', 'in', 'initially', 'inner', 'inout', 'int', 'integer', 'intersect', 'interval', 'into', 'is', 'isnull', 'join', 'json', 'json_array', 'json_arrayagg', 'json_exists', 'json_object', 'json_objectagg', 'json_query', 'json_scalar', 'json_serialize', 'json_table', 'json_value', 'lateral', 'leading', 'least', 'left', 'like', 'limit', 'localtime', 'localtimestamp', 'merge_action', 'national', 'natural', 'nchar', 'none', 'normalize', 'not', 'notnull', 'null', 'nullif', 'numeric', 'offset', 'on', 'only', 'or', 'order', 'out', 'outer', 'overlaps', 'overlay', 'placing', 'position', 'precision', 'primary', 'real', 'references', 'returning', 'right', 'row', 'select', 'session_user', 'setof', 'similar', 'smallint', 'some', 'substring', 'symmetric', 'system_user', 'table', 'tablesample', 'then', 'time', 'timestamp', 'to', 'trailing', 'treat', 'trim', 'true', 'union', 'unique', 'user', 'using', 'values', 'varchar', 'variadic', 'verbose', 'when', 'where', 'window', 'with', 'xmlattributes', 'xmlconcat', 'xmlelement', 'xmlexists', 'xmlforest', 'xmlnamespaces', 'xmlparse', 'xmlpi', 'xmlroot', 'xmlserialize', 'xmltable'];

        if ($major === 15) {
            $keywords = array_diff($keywords, ['json', 'json_array', 'json_arrayagg', 'json_exists', 'json_object', 'json_objectagg', 'json_query', 'json_scalar', 'json_serialize', 'json_table', 'json_value', 'merge_action', 'system_user']);
        }

        if ($major === 16) {
            $keywords = array_diff($keywords, ['json', 'json_exists', 'json_query', 'json_scalar', 'json_serialize', 'json_table', 'json_value', 'merge_action']);
        }

        return preg_match('/\A[a-z_][a-z_0-9]*\z/', $identifier) === 1 && ! in_array($identifier, $keywords, true)
            ? $identifier : '"'.str_replace('"', '""', $identifier).'"';
    }
}
