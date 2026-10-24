<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use RuntimeException;

/** Recognizes qualified identifiers in managed replay SQL, not arbitrary PHP effects. */
final class ReplaySqlGuard
{
    /**
     * @param  list<string>  $originals
     * @param  list<string>  $ownedNamespaces
     */
    public static function assertAllowedNamespaces(string $sql, array $originals, array $ownedNamespaces, string $driver): void
    {
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new RuntimeException('Unsupported replay SQL dialect.');
        }
        $comments = $driver === 'mysql'
            ? '--(?=[\x00-\x20]|$)[^\r\n]*(?:\r\n|[\r\n]|$)|\#[^\r\n]*(?:\r\n|[\r\n]|$)'
            : '--[^\r\n]*(?:\r\n|[\r\n]|$)';
        $dollar = $driver === 'pgsql' ? '\$\$.*?\$\$|\$(?<tag>[a-zA-Z_][a-zA-Z_0-9]*)\$.*?\$\k<tag>\$' : '(?!)';
        $bare = $driver === 'mysql' ? '[a-zA-Z_0-9$\x80-\xff]+' : '[a-zA-Z_\x80-\xff][a-zA-Z_0-9$\x80-\xff]*';
        $number = '(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?(?![a-zA-Z_0-9$\x80-\xff.])';
        $pattern = '~\\G(?:(?<space>\\s+)|(?<comment>'.$comments.'|(?<block>/\\*(?:[^/*]+|/(?!\\*)|\\*(?!/)|(?&block))*\\*/))|(?<unicode>[uU]&(?:"(?:[^"]|"")*"|\'(?:[^\']|\'\')*\'))|(?<escaped>[eE]\'(?:[^\'\\\\]|\\\\.|\'\')*\')|(?<dollar>'.$dollar.')|(?<string>\'(?:[^\']|\'\')*\')|(?<number>'.$number.')|(?<identifier>"(?:[^"]|"")*"|`(?:[^`]|``)*`|'.$bare.')|(?<symbol>[^\'"`\\s]))~s';
        $offset = 0;
        $statement = [];
        while ($offset < strlen($sql)) {
            if (preg_match($pattern, $sql, $token, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                throw new RuntimeException('Unsupported or malformed replay SQL token.');
            }
            $offset += strlen($token[0]);

            if ($token['unicode'] !== null
                || (($token['string'] !== null || $token['escaped'] !== null || ($driver === 'mysql' && $token['identifier'] !== null))
                    && str_contains($token[0], '\\'))) {
                throw new RuntimeException('Escape-dependent SQL quoting is unsupported during replay.');
            }

            if ($token['comment'] !== null) {
                if (str_contains($token[0], '/*!') || str_contains($token[0], '/*M!')
                    || ($driver === 'mysql' && str_contains(substr($token[0], 2), '/*'))) {
                    throw new RuntimeException('Executable SQL comments are unsupported during replay.');
                }

                continue;
            }

            if ($token['space'] !== null) {
                continue;
            }

            $nextKeyword = $token['identifier'] !== null && ! in_array($token[0][0], ['"', '`'], true)
                ? strtoupper($token[0]) : null;

            $identifier = null;

            if ($token['identifier'] !== null) {
                $identifier = $token[0];
                $quote = $identifier[0];

                if ($quote === '"' || $quote === '`') {
                    $identifier = str_replace($quote.$quote, $quote, substr($identifier, 1, -1));
                } elseif ($driver === 'pgsql') {
                    $identifier = strtolower($identifier);
                }
            }

            if ($token['symbol'] === ';') {
                self::assertStatementScope($statement, $originals, $ownedNamespaces, $driver);
                $statement = [];
            } else {
                $statement[] = ['keyword' => $nextKeyword, 'identifier' => $identifier, 'symbol' => $token['symbol']];
            }
        }
        self::assertStatementScope($statement, $originals, $ownedNamespaces, $driver);
    }

    /**
     * @param  list<array{keyword: ?string, identifier: ?string, symbol: ?string}>  $statement
     * @param  list<string>  $originals
     * @param  list<string>  $ownedNamespaces
     */
    private static function assertStatementScope(array $statement, array $originals, array $ownedNamespaces, string $driver): void
    {
        if ($statement === []) {
            return;
        }
        $command = $statement[0]['keyword'] ?? null;
        $subject = $statement[1]['keyword'] ?? null;

        foreach ($statement as $offset => $token) {
            $identifier = $token['identifier'];

            if ($identifier === null || ($statement[$offset + 1]['symbol'] ?? null) !== '.') {
                continue;
            }

            if ($driver === 'pgsql' && strlen($identifier) > 63) {
                throw new RuntimeException('Overlong PostgreSQL namespace qualifiers are unsupported during replay.');
            }
            foreach ($originals as $original) {
                if (strcasecmp($identifier, $original) === 0
                    && ! ($driver === 'pgsql' && self::isColumnReference($statement, $offset))) {
                    throw new RuntimeException('Unsupported replay SQL references the original namespace.');
                }
            }
        }

        // Unknown command families cannot inherit permission from a redirected search path.
        if (! in_array($command, ['SELECT', 'WITH', 'TABLE', 'VALUES', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE',
            'SET', 'SHOW', 'DESCRIBE', 'DESC', 'BEGIN', 'START', 'COMMIT', 'END', 'ROLLBACK', 'ABORT', 'SAVEPOINT', 'RELEASE',
            'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME', 'COMMENT'], true)
            || ($command === 'START' && $subject !== 'TRANSACTION')
            || ($command === 'RELEASE' && $subject !== 'SAVEPOINT')
            || (in_array($command, ['COMMIT', 'END', 'ROLLBACK', 'ABORT'], true) && $subject === 'PREPARED')) {
            throw new RuntimeException('Unsupported replay SQL statement scope.');
        }

        $context = self::queryContext($statement);
        $declarations = $context['declarations'] + self::assertDmlTargets($statement, $context['commands'], $ownedNamespaces, $driver);

        foreach ($statement as $offset => $token) {
            if (($statement[$offset + 1]['symbol'] ?? null) !== '(') {
                continue;
            }
            $function = strtolower($token['identifier'] ?? '');

            if (! in_array($function, ['set_config', 'setval', 'nextval'], true)
                || isset($declarations[$offset]) || self::isRelationDeclaration($statement, $offset)) {
                continue;
            }

            if ($function === 'set_config') {
                throw new RuntimeException('Unsupported replay SQL changes session or global scope.');
            }

            // regclass arguments can be literals, OIDs, bindings or expressions. A redirected
            // search path does not prove their ownership, and sequence effects do not roll back.
            if ($driver === 'pgsql') {
                throw new RuntimeException('Explicit sequence mutation functions are unsupported during replay.');
            }
        }

        if ($command === 'SELECT' || $command === 'WITH') {
            self::assertNoSelectInto($statement);
        }

        if ($command === 'SET') {
            self::assertLocalAssignments($statement);
            $offset = in_array($subject, ['LOCAL', 'SESSION'], true) ? 2 : 1;
            // Setting names are identifiers, including quoted PostgreSQL GUC names.
            $setting = strtolower($statement[$offset]['identifier'] ?? '');

            if (in_array($setting, ['search_path', 'schema', 'role', 'session_authorization', 'global', 'password'], true)
                || ($subject === 'DEFAULT' && ($statement[2]['keyword'] ?? null) === 'ROLE')
                || ($setting === 'authorization' && $subject === 'SESSION')
                || ($setting === 'session' && ($statement[$offset + 1]['keyword'] ?? null) === 'AUTHORIZATION')) {
                throw new RuntimeException('Unsupported replay SQL changes session or global scope.');
            }
        }

        if (! in_array($command, ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME', 'COMMENT'], true)) {
            return;
        }

        self::assertSupportedDdl($statement);
        $columnReferences = $driver === 'pgsql' ? self::assertPostgresDdlTargets($statement, $ownedNamespaces) : [];
        $columnComment = $command === 'COMMENT' && ($statement[1]['keyword'] ?? null) === 'ON'
            && ($statement[2]['keyword'] ?? null) === 'COLUMN';

        for ($i = 0; $i < count($statement); $i++) {
            if ($statement[$i]['keyword'] === 'SET' && ($statement[$i + 1]['keyword'] ?? null) === 'SCHEMA') {
                throw new RuntimeException('Unsupported replay SQL changes namespace ownership.');
            }
            $namespace = $statement[$i]['identifier'];
            $parts = 1;
            $unqualifiedParts = $columnComment || isset($columnReferences[$i]) ? 2 : 1;

            while ($namespace !== null && ($statement[$i + 1]['symbol'] ?? null) === '.'
                && ($statement[$i + 2]['identifier'] ?? null) !== null) {
                $parts++;
                $i += 2;
            }

            // COMMENT ON COLUMN uses table.column or schema.table.column.
            if ($parts <= $unqualifiedParts) {
                continue;
            }

            // Other ambiguous qualifiers (including raw DDL aliases) fail closed.
            if ($parts !== $unqualifiedParts + 1 || ! in_array($namespace, $ownedNamespaces, true)) {
                throw new RuntimeException('Unsupported replay DDL references a namespace not owned by this run.');
            }
        }
    }

    /** @param list<array{keyword: ?string, identifier: ?string, symbol: ?string}> $statement */
    private static function isColumnReference(array $statement, int $offset): bool
    {
        // In a query expression, a two-part name is table/alias.column. Relation,
        // function, type and collation positions still use schema qualification.
        if (! in_array($statement[0]['keyword'], ['SELECT', 'WITH', 'INSERT', 'UPDATE', 'DELETE'], true)
            || in_array($statement[$offset - 1]['symbol'] ?? null, ['.', ':'], true)
            || (($statement[$offset + 2]['identifier'] ?? null) === null && ($statement[$offset + 2]['symbol'] ?? null) !== '*')
            || in_array($statement[$offset + 3]['symbol'] ?? null, ['.', '('], true)) {
            return false;
        }
        $after = $statement[$offset + 3] ?? null;

        // A qualified type followed by a literal is not a column expression.
        if ($after !== null && $after['identifier'] === null && $after['symbol'] === null) {
            return false;
        }
        $clause = null;
        $list = null;
        $stack = [];
        for ($i = 0; $i < $offset; $i++) {
            $token = $statement[$i];

            if ($token['symbol'] === '(') {
                $stack[] = [$clause, $list];
                // Expression commas stay inside their group. Parenthesized FROM
                // lists retain relation context until a nested query begins.
                $list = $clause === 'relation' ? 'relation' : null;
            } elseif ($token['symbol'] === ')') {
                [$clause, $list] = array_pop($stack) ?? [null, null];
            } elseif ($token['symbol'] === ',' && $list !== null) {
                $clause = $list;
            } elseif ($token['keyword'] === 'SELECT') {
                $clause = $list = 'expression';
            } elseif (in_array($token['keyword'], ['FROM', 'JOIN', 'TABLE', 'INTO', 'UPDATE', 'USING'], true)) {
                $clause = $list = 'relation';
            } elseif (in_array($token['keyword'], ['WHERE', 'HAVING', 'GROUP', 'ORDER'], true)) {
                $clause = 'expression';
                $list = null;
            } elseif ($token['keyword'] === 'ON') {
                $clause = 'expression';
            } elseif (in_array($token['keyword'], ['AS', 'COLLATE', 'OF', 'RETURNING'], true)) {
                $clause = null;
            }
        }

        return $clause === 'expression';
    }

    /**
     * @param  list<array{keyword: ?string, identifier: ?string, symbol: ?string}>  $statement
     * @return array{commands: list<int>, declarations: array<int, true>}
     */
    private static function queryContext(array $statement): array
    {
        $stack = [];
        $groups = [];
        foreach ($statement as $offset => $token) {
            if ($token['symbol'] === '(') {
                $stack[] = $offset;
            } elseif ($token['symbol'] === ')') {
                $open = array_pop($stack);

                if ($open === null) {
                    throw new RuntimeException('Unbalanced replay SQL parentheses.');
                }
                $groups[$open] = $offset;
            }
        }

        if ($stack !== []) {
            throw new RuntimeException('Unbalanced replay SQL parentheses.');
        }
        $heads = [0 => true];
        $columns = [];
        $declarations = [];
        $commands = [];
        $depth = 0;
        $createQueryFound = false;
        foreach ($statement as $offset => $token) {
            if (isset($heads[$offset])) {
                if ($token['keyword'] === 'WITH') {
                    $next = $offset + 1;
                    $next += ($statement[$next]['keyword'] ?? null) === 'RECURSIVE' ? 1 : 0;
                    do {
                        if (($statement[$next]['identifier'] ?? null) === null) {
                            throw new RuntimeException('Unsupported replay CTE declaration.');
                        }
                        $declarations[$next++] = true;

                        if (isset($groups[$next])) {
                            $columns[$next] = true;
                            $next = $groups[$next] + 1;
                        }

                        if (($statement[$next++]['keyword'] ?? null) !== 'AS') {
                            throw new RuntimeException('Unsupported replay CTE declaration.');
                        }

                        if (($statement[$next]['keyword'] ?? null) === 'NOT') {
                            $next++;
                        }

                        if (($statement[$next]['keyword'] ?? null) === 'MATERIALIZED') {
                            $next++;
                        }

                        if (! isset($groups[$next])) {
                            throw new RuntimeException('Unsupported replay CTE body.');
                        }
                        $heads[$next + 1] = true;
                        $next = $groups[$next] + 1;

                        if (in_array($statement[$next]['keyword'] ?? null, ['SEARCH', 'CYCLE'], true)) {
                            throw new RuntimeException('Replay CTE SEARCH and CYCLE clauses are unsupported.');
                        }
                        $another = ($statement[$next]['symbol'] ?? null) === ',';
                        $next += $another ? 1 : 0;
                    } while ($another);
                    $heads[$next] = true;
                } elseif (in_array($token['keyword'], ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE'], true)) {
                    $commands[] = $offset;
                }
            }

            if ($token['symbol'] === '(') {
                if (! isset($columns[$offset]) && in_array($statement[$offset + 1]['keyword'] ?? null, ['SELECT', 'WITH'], true)) {
                    $heads[$offset + 1] = true;
                }
                $depth++;
            } elseif ($token['symbol'] === ')') {
                $depth--;
            } elseif ($statement[0]['keyword'] === 'CREATE' && ! $createQueryFound && $depth === 0 && $token['keyword'] === 'AS') {
                // CREATE TABLE ... AS may contain a writable WITH query.
                $heads[$offset + 1] = true;
                $createQueryFound = true;
            }
        }

        return ['commands' => $commands, 'declarations' => $declarations];
    }

    /** @param list<array{keyword: ?string, identifier: ?string, symbol: ?string}> $statement */
    private static function isRelationDeclaration(array $statement, int $offset): bool
    {
        if (($statement[$offset - 1]['keyword'] ?? null) === 'AS') {
            return true;
        }
        // A CREATE/ALTER TABLE target can have a column list, including a qualified name.
        $nameStart = ($statement[$offset - 1]['symbol'] ?? null) === '.' ? $offset - 2 : $offset;

        if (in_array($statement[$nameStart - 1]['keyword'] ?? null, ['TABLE', 'EXISTS'], true)) {
            return true;
        }
        // An implicit alias must follow a relation source, not an expression such
        // as OPERATOR(...). Its closing parenthesis alone does not prove this.
        $relation = $offset - 1;

        if (($statement[$relation]['symbol'] ?? null) === ')') {
            $depth = 1;
            while ($relation > 0 && $depth > 0) {
                $relation--;
                $depth += $statement[$relation]['symbol'] === ')' ? 1 : 0;
                $depth -= $statement[$relation]['symbol'] === '(' ? 1 : 0;
            }

            if (($statement[$relation - 1]['identifier'] ?? null) !== null
                && ! in_array($statement[$relation - 1]['keyword'], ['FROM', 'JOIN', 'LATERAL', 'ONLY'], true)) {
                $relation--;

                if ($statement[$relation]['keyword'] === 'OPERATOR') {
                    return false;
                }
            }
        } elseif (($statement[$relation]['identifier'] ?? null) === null
            || in_array($statement[$relation]['keyword'], ['LATERAL', 'ONLY'], true)) {
            return false;
        }

        if (($statement[$relation - 1]['symbol'] ?? null) === '.') {
            $relation -= 2;
        }

        if (in_array($statement[$relation - 1]['keyword'] ?? null, ['LATERAL', 'ONLY'], true)) {
            $relation--;
        }

        if (in_array($statement[$relation - 1]['keyword'] ?? null, ['FROM', 'JOIN'], true)) {
            return true;
        }

        if (($statement[$relation - 1]['symbol'] ?? null) !== ',') {
            return false;
        }
        // A comma can continue a FROM list, but a comma inside an expression
        // cannot establish an alias. Stay at this query's parenthesis level.
        $depth = 0;
        for ($i = $relation - 2; $i >= 0; $i--) {
            if ($statement[$i]['symbol'] === ')') {
                $depth++;
            } elseif ($statement[$i]['symbol'] === '(') {
                if ($depth === 0) {
                    return false;
                }
                $depth--;
            } elseif ($depth === 0) {
                if (in_array($statement[$i]['keyword'], ['FROM', 'JOIN'], true)) {
                    return true;
                }

                if (in_array($statement[$i]['keyword'], ['SELECT', 'WITH', 'WHERE', 'GROUP', 'HAVING', 'WINDOW', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH', 'RETURNING', 'SET', 'VALUES'], true)) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{keyword: ?string, identifier: ?string, symbol: ?string}>  $statement
     * @param  list<int>  $commands
     * @param  list<string>  $ownedNamespaces
     * @return array<int, true>
     */
    private static function assertDmlTargets(array $statement, array $commands, array $ownedNamespaces, string $driver): array
    {
        $declarations = [];
        foreach ($commands as $offset) {
            $command = $statement[$offset]['keyword'];

            if ($command === 'MERGE') {
                throw new RuntimeException('Unsupported replay DML has an unverified MERGE target.');
            }

            if (in_array($command, ['INSERT', 'REPLACE'], true) && ($statement[$offset + 1]['symbol'] ?? null) === '(') {
                continue;
            }
            $target = $offset + 1;

            if ($driver === 'mysql') {
                while (in_array($statement[$target]['keyword'] ?? null, ['LOW_PRIORITY', 'HIGH_PRIORITY', 'DELAYED', 'IGNORE', 'QUICK'], true)) {
                    $target++;
                }
            }

            if ($command === 'DELETE') {
                if (($statement[$target]['keyword'] ?? null) !== 'FROM') {
                    throw new RuntimeException('Multi-table replay DML has an unverified write target.');
                }
                $target++;
            } elseif (in_array($command, ['INSERT', 'REPLACE'], true) && ($statement[$target]['keyword'] ?? null) === 'INTO') {
                $target++;
            }
            $only = $driver === 'pgsql' && ($statement[$target]['keyword'] ?? null) === 'ONLY';
            $target += $only ? 1 : 0;
            $parenthesized = $only && ($statement[$target]['symbol'] ?? null) === '(';
            $target += $parenthesized ? 1 : 0;
            $end = self::assertOwnedRelation($statement, $target, $ownedNamespaces, $driver, 'DML');
            $declarations[$end - 1] = true;

            if ($parenthesized) {
                if (($statement[$end]['symbol'] ?? null) !== ')') {
                    throw new RuntimeException('Unsupported replay DML relation syntax.');
                }
                $end++;
            }

            if ($driver === 'pgsql' && ($statement[$end]['symbol'] ?? null) === '*') {
                $end++;
            }

            if ($command === 'UPDATE' || ($command === 'DELETE' && $driver === 'mysql')) {
                // A single target may have an alias. Requiring the following clause keeps
                // MySQL joins, comma lists and DELETE ... USING from hiding additional targets.
                $clauses = $command === 'UPDATE' ? ['SET'] : ['WHERE', 'ORDER', 'LIMIT'];

                if (($statement[$end]['keyword'] ?? null) === 'AS') {
                    $end++;
                }

                if (($statement[$end]['identifier'] ?? null) !== null && ! in_array($statement[$end]['keyword'], $clauses, true)) {
                    $end++;
                }

                if (! in_array($statement[$end]['keyword'] ?? null, $clauses, true)
                    && ! ($command === 'DELETE' && (! isset($statement[$end]) || $statement[$end]['symbol'] === ')'))) {
                    throw new RuntimeException('Multi-table or unsupported replay DML has an unverified write target.');
                }
            }
        }

        return $declarations;
    }

    /**
     * @param  list<array{keyword: ?string, identifier: ?string, symbol: ?string}>  $statement
     * @param  list<string>  $ownedNamespaces
     */
    private static function assertOwnedRelation(array $statement, int $offset, array $ownedNamespaces, string $driver, string $kind, bool $column = false): int
    {
        $identifier = $statement[$offset]['identifier'] ?? null;

        if ($identifier === null) {
            throw new RuntimeException('Unsupported replay '.$kind.' has an unverified write target.');
        }
        $offset++;
        $parts = 1;

        while (($statement[$offset]['symbol'] ?? null) === '.') {
            if (($statement[$offset + 1]['identifier'] ?? null) === null) {
                throw new RuntimeException('Unsupported replay '.$kind.' has an unverified write target.');
            }
            $parts++;
            $offset += 2;
        }
        $unqualifiedParts = $column ? 2 : 1;

        if ($parts < $unqualifiedParts || $parts > $unqualifiedParts + 1
            || ($parts === $unqualifiedParts + 1 && ! in_array($identifier, $ownedNamespaces, true))) {
            throw new RuntimeException('Unsupported replay '.$kind.' references a namespace not owned by this run.');
        }

        if ($parts === $unqualifiedParts + 1) {
            return $offset;
        } elseif ($driver === 'pgsql' && str_starts_with(strtolower($identifier), 'pg_')) {
            // pg_catalog is implicitly searched before the redirected search path.
            // Require explicit ownership for names that could resolve to system relations.
            throw new RuntimeException('Unsupported replay '.$kind.' has an unqualified PostgreSQL system relation target.');
        }

        return $offset;
    }

    /**
     * @param  list<array{keyword: ?string, identifier: ?string, symbol: ?string}>  $statement
     * @param  list<string>  $ownedNamespaces
     * @return array<int, true>
     */
    private static function assertPostgresDdlTargets(array $statement, array $ownedNamespaces): array
    {
        $command = $statement[0]['keyword'];
        $kind = $statement[1]['keyword'];
        $columnReferences = [];

        if ($command === 'COMMENT') {
            $column = $statement[2]['keyword'] === 'COLUMN';
            $target = $statement[2]['keyword'] === 'CONSTRAINT' ? 5 : 3;

            if ($target === 5 && (($statement[4]['keyword'] ?? null) !== 'ON' || ($statement[5]['keyword'] ?? null) === 'DOMAIN')) {
                throw new RuntimeException('Unsupported replay DDL has an unverified constraint target.');
            }
            self::assertOwnedRelation($statement, $target, $ownedNamespaces, 'pgsql', 'DDL', $column);

            return $column ? [$target => true] : [];
        }

        $target = match ($command) {
            'ALTER', 'DROP' => 2,
            'TRUNCATE' => $kind === 'TABLE' ? 2 : 1,
            default => null,
        };

        if ($command === 'CREATE' && in_array($kind, ['INDEX', 'UNIQUE'], true)) {
            foreach ($statement as $offset => $token) {
                if ($token['keyword'] === 'ON') {
                    $target = $offset + 1;

                    break;
                }
            }

            if ($target === null) {
                throw new RuntimeException('Unsupported replay DDL has an unverified index target.');
            }
        }

        if ($target !== null) {
            while (in_array($statement[$target]['keyword'] ?? null, ['IF', 'EXISTS', 'CONCURRENTLY'], true)) {
                $target++;
            }
            $target = self::assertDdlRelationList($statement, $target, $ownedNamespaces, in_array($command, ['DROP', 'TRUNCATE'], true));
        }

        // New object names do not resolve existing relations. These clauses do, including
        // secondary targets that can change inheritance, partition or constraint metadata.
        $depth = 0;
        $action = $target;
        foreach ($statement as $offset => $token) {
            if ($token['symbol'] === '(') {
                $depth++;
            } elseif ($token['symbol'] === ')') {
                $depth--;
            } elseif ($depth === 0 && $token['symbol'] === ',') {
                $action = $offset + 1;
            }
            $next = $statement[$offset + 1]['keyword'] ?? null;
            $relation = null;

            if (in_array($command, ['CREATE', 'ALTER'], true) && $token['keyword'] === 'REFERENCES') {
                $relation = $offset + 1;
            } elseif ($depth === 0 && $command === 'CREATE' && $token['keyword'] === 'INHERITS'
                && ($statement[$offset + 1]['symbol'] ?? null) === '(') {
                self::assertDdlRelationList($statement, $offset + 2, $ownedNamespaces, true);
            } elseif ($depth === 0 && (($command === 'CREATE' && $token['keyword'] === 'PARTITION' && $next === 'OF')
                || ($command === 'ALTER' && in_array($token['keyword'], ['ATTACH', 'DETACH'], true) && $next === 'PARTITION'))) {
                $relation = $offset + 2;
            } elseif ($depth === 0 && $command === 'ALTER' && $token['keyword'] === 'INHERIT'
                && ($offset === $action || ($offset === $action + 1 && ($statement[$action]['keyword'] ?? null) === 'NO'))) {
                $relation = $offset + 1;
            } elseif ($depth === 0 && $kind === 'SEQUENCE' && $token['keyword'] === 'OWNED' && $next === 'BY'
                && ($statement[$offset + 2]['keyword'] ?? null) !== 'NONE') {
                $relation = $offset + 2;
                $columnReferences[$relation] = true;
            }

            if ($relation !== null) {
                self::assertOwnedRelation($statement, $relation, $ownedNamespaces, 'pgsql', 'DDL', isset($columnReferences[$relation]));
            }
        }

        return $columnReferences;
    }

    /**
     * @param  list<array{keyword: ?string, identifier: ?string, symbol: ?string}>  $statement
     * @param  list<string>  $ownedNamespaces
     */
    private static function assertDdlRelationList(array $statement, int $offset, array $ownedNamespaces, bool $list): int
    {
        do {
            $only = ($statement[$offset]['keyword'] ?? null) === 'ONLY';
            $offset += $only ? 1 : 0;
            $parenthesized = $only && ($statement[$offset]['symbol'] ?? null) === '(';
            $offset += $parenthesized ? 1 : 0;
            $offset = self::assertOwnedRelation($statement, $offset, $ownedNamespaces, 'pgsql', 'DDL');

            if ($parenthesized) {
                if (($statement[$offset]['symbol'] ?? null) !== ')') {
                    throw new RuntimeException('Unsupported replay DDL relation syntax.');
                }
                $offset++;
            }
            $offset += ($statement[$offset]['symbol'] ?? null) === '*' ? 1 : 0;
            $another = $list && ($statement[$offset]['symbol'] ?? null) === ',';
            $offset += $another ? 1 : 0;
        } while ($another);

        return $offset;
    }

    /** @param list<array{keyword: ?string, identifier: ?string, symbol: ?string}> $statement */
    private static function assertNoSelectInto(array $statement): void
    {
        $hasSelect = [];
        $depth = 0;
        foreach ($statement as $token) {
            if ($token['symbol'] === '(') {
                $depth++;
            } elseif ($token['symbol'] === ')') {
                unset($hasSelect[$depth]);
                $depth--;
            } elseif ($token['keyword'] === 'SELECT') {
                $hasSelect[$depth] = true;
            } elseif ($token['keyword'] === 'INTO' && ($hasSelect[$depth] ?? false)) {
                throw new RuntimeException('SELECT INTO has an unverified replay output target.');
            }
        }
    }

    /** @param list<array{keyword: ?string, identifier: ?string, symbol: ?string}> $statement */
    private static function assertSupportedDdl(array $statement): void
    {
        $kind = $statement[1]['keyword'] ?? null;
        $next = $statement[2]['keyword'] ?? null;
        $supported = match ($statement[0]['keyword']) {
            'CREATE' => in_array($kind, ['TABLE', 'INDEX', 'SEQUENCE'], true)
                || (in_array($kind, ['TEMP', 'TEMPORARY', 'UNLOGGED'], true) && $next === 'TABLE')
                || (in_array($kind, ['UNIQUE', 'FULLTEXT', 'SPATIAL'], true) && $next === 'INDEX'),
            // ALL IN TABLESPACE targets original relations outside the replay search path.
            'ALTER' => in_array($kind, ['TABLE', 'INDEX', 'SEQUENCE'], true) && $next !== 'ALL',
            'DROP' => in_array($kind, ['TABLE', 'INDEX', 'SEQUENCE'], true) || ($kind === 'TEMPORARY' && $next === 'TABLE'),
            'COMMENT' => $kind === 'ON' && in_array($next, ['TABLE', 'COLUMN', 'INDEX', 'CONSTRAINT'], true),
            'RENAME' => $kind === 'TABLE',
            'TRUNCATE' => true,
            default => false,
        };

        if (! $supported) {
            throw new RuntimeException('Unsupported replay DDL changes global scope or has an unverified object kind.');
        }
    }

    /** @param list<array{keyword: ?string, identifier: ?string, symbol: ?string}> $statement */
    private static function assertLocalAssignments(array $statement): void
    {
        $start = 1;
        $left = true;
        $depth = 0;
        for ($i = 1; $i < count($statement); $i++) {
            $token = $statement[$i];

            if ($depth === 0) {
                if ($token['symbol'] === ',') {
                    $start = $i + 1;
                    $left = true;
                } elseif ($token['symbol'] === '=') {
                    $left = false;
                }
                // SET can mix session and global assignments in the same statement.
                $directive = $i === $start && in_array($token['keyword'], ['GLOBAL', 'PERSIST', 'PERSIST_ONLY'], true);
                $globalVariable = $left && $token['symbol'] === '@' && ($statement[$i + 1]['symbol'] ?? null) === '@'
                    && in_array(strtoupper($statement[$i + 2]['identifier'] ?? ''), ['GLOBAL', 'PERSIST', 'PERSIST_ONLY'], true);

                if ($directive || $globalVariable) {
                    throw new RuntimeException('Unsupported replay SQL changes global scope.');
                }
            }

            if ($token['symbol'] === '(') {
                $depth++;
            } elseif ($token['symbol'] === ')') {
                $depth--;
            }
        }
    }
}
