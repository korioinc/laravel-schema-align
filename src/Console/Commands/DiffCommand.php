<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Console\Commands;

use Illuminate\Console\Command;
use Korioinc\SchemaAlign\Planning\Operation;
use Korioinc\SchemaAlign\SchemaAlign;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class DiffCommand extends Command
{
    protected $signature = 'schema-align:diff {--json : Emit machine-readable comparison instead of the human summary} {--details : Include definitions, SQL, history and connection diagnostics; full snapshots with --json}';

    protected $description = 'Read original objects and compare full migrations replayed in owned temporary namespaces';

    protected $help = <<<'HELP'
Application command, not a package development test. Original objects are only read.
Full trusted migrations run in fresh owned namespaces on the configured database server.
PostgreSQL creates schemas in the original DB; MySQL creates a database on the same server.

Uses the consuming application's resolved connection, PHP runtime and matching PDO extension.
Docker, database CLI clients and image preparation are not required.
Writable credentials need namespace creation, migration and cleanup privileges.
PostgreSQL superusers and MySQL administrators can compare and apply approved signed plans.
Both engines use the configured app account; metadata visibility and ownership checks remain required.
Original PostgreSQL schemas are configured with engines.pgsql.schemas, default [public].
Trusted application code is not sandboxed from mounted secrets or original privileges.
Resolved credentials reach the worker through private input, never command arguments.
Uses installed application files directly; only worker cache and storage use private temporary paths.
Cleanup failures report an exact run ID for schema-align:cleanup.

Default output groups readable changes by table with automatic/manual counts and next steps.
After a successful comparison, extra tables appear in a red manual-review section at the bottom.
Use --details for definitions, SQL, migration-history and connection diagnostics.
Use --json for the compact machine result, or --json --details for complete snapshots.
Migration replay means local PHP execution in temporary namespaces, not Docker.
This changes output detail only; comparison coverage and original-object preservation are identical.

Exit codes: 0 = clean, 2 = drift, 1 = failed or incomplete comparison.
HELP;

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function summary(array $result): array
    {
        $differences = [];
        foreach ($result['differences'] as $difference) {
            $item = array_intersect_key($difference, array_flip(['id', 'kind', 'table', 'name', 'old_name', 'automatic']));

            if ($difference['reason'] !== null) {
                $item['reason'] = $difference['reason'];
            }
            $differences[] = $item;
        }
        $unsupported = [];
        foreach (['desired', 'actual'] as $side) {
            foreach ($result[$side]['unsupported'] as $issue) {
                $unsupported[] = ['side' => $side, 'issue' => $issue];
            }
        }

        return [
            'status' => $result['status'],
            'target' => array_intersect_key($result['binding'], array_flip(['environment', 'database'])),
            'source' => array_intersect_key($result['source'], array_flip(['revision', 'migration_digest'])),
            'differences' => $differences,
            'errors' => $result['errors'],
            'warnings' => array_values(array_diff($result['warnings'], $result['connection_warnings'] ?? [])),
            'unsupported' => $unsupported,
        ];
    }

    /** @param array<string, mixed> $result */
    private function show(array $result): void
    {
        $details = (bool) $this->option('details');
        $this->info('Schema Align diff');
        $binding = $result['binding'];
        $this->line('Target: '.self::plain(($binding['environment'] ?? '').' / '.($binding['database'] ?? '').' @ '.($binding['host'] ?? '').':'.($binding['port'] ?? '')));
        $differences = $result['differences'];
        $automatic = count(array_filter($differences, static fn (array $difference): bool => $difference['automatic']));
        $manual = count($differences) - $automatic;
        $failed = $result['status'] === 'failure';
        $this->newLine();

        if ($failed) {
            $this->error('Comparison failed or incomplete. No executable plan can be created.');
        } elseif ($differences === []) {
            $this->info('No schema changes. The database matches the current migrations.');
        } else {
            $this->line('Changes: '.count($differences).' total / '.$automatic.' automatic / '.$manual.' manual review');
        }
        $table = null;
        foreach ($differences as $difference) {
            if ($table !== $difference['table']) {
                $table = $difference['table'];
                $this->newLine();
                $this->line(self::plain($table));
            }
            $kind = $difference['kind'];
            $marker = str_starts_with($kind, 'add_') || str_starts_with($kind, 'create_') ? '+' : (str_starts_with($kind, 'extra_') || str_starts_with($kind, 'drop_') ? '-' : '~');
            $mode = $difference['automatic'] ? 'automatic' : 'manual';
            $this->line('  '.$marker.' '.self::plain(str_replace('_', ' ', $kind).' '.($difference['name'] ?? '')).' ['.$mode.']');

            if (is_string($difference['reason'] ?? null) && $difference['reason'] !== '') {
                $this->line('    '.self::plain($difference['reason']));
            }

            if ($details) {
                $this->line('    ID: '.self::plain($difference['id']));

                if (($difference['old_name'] ?? null) !== null) {
                    $this->line('    Previous name: '.self::plain($difference['old_name']));
                }
                foreach (['before', 'after'] as $side) {
                    if (($difference[$side] ?? null) !== null) {
                        $this->line('    '.ucfirst($side).': '.self::plain(json_encode($difference[$side], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
                    }
                }
                foreach ($difference['sql'] as $sql) {
                    $this->line('    SQL: '.self::plain($sql));
                }
            }
        }
        foreach ($result['errors'] as $error) {
            $this->error(self::plain($error));
        }
        $warnings = $details ? $result['warnings'] : array_diff($result['warnings'], $result['connection_warnings'] ?? []);
        foreach ($warnings as $warning) {
            $this->warn(self::plain($warning));
        }

        if ($details) {
            foreach ($result['historical_missing_files'] as $migration) {
                $this->line('Historical migration file not in current source: '.self::plain($migration));
            }
        }
        $this->newLine();
        $this->line('Original database objects were not changed.');

        if (! $failed && ($automatic > 0 || Operation::hasColumnDrops($differences))) {
            $this->line('Next: php artisan schema-align:plan');
        } elseif (! $failed && $manual > 0) {
            $this->line('Review manual changes before creating a plan.');
        }

        if (! $details && $differences !== []) {
            $this->line('Use --details for operation IDs and SQL, or --json for automation.');
        }

        $extraTables = array_column(array_filter($differences, static fn (array $difference): bool => $difference['kind'] === 'extra_table'), 'table');

        if (! $failed && $extraTables !== []) {
            $this->newLine();
            $this->error('Tables absent from current migrations (manual review):');
            foreach ($extraTables as $extraTable) {
                $this->error('  - '.self::plain($extraTable));
            }
            $this->error('These tables exist only in the current database. Review whether they are still needed.');
            $this->error('Drop them manually if appropriate. Dropping a table permanently deletes its data.');
            $this->error('schema-align does not drop tables.');
        }
    }

    private static function plain(string $value): string
    {
        return OutputFormatter::escape((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $value));
    }

    public function handle(SchemaAlign $align): int
    {
        try {
            $result = $align->diff();

            if ($this->option('json')) {
                $output = $this->option('details') ? $result : $this->summary($result);
                $this->output->writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                $this->show($result);
            }

            return $result['status'] === 'failure' ? 1 : ($result['status'] === 'drift' ? 2 : 0);
        } catch (Throwable $error) {
            if ($this->option('json')) {
                $this->output->writeln(json_encode(['status' => 'failure', 'errors' => [$error->getMessage()], 'warnings' => [], 'unsupported' => []], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                $this->error(self::plain($error->getMessage()));
            }

            return 1;
        }
    }
}
