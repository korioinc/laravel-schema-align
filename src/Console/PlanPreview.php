<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** Shared human review of the exact signed operations that apply will execute. */
final class PlanPreview
{
    /** @param array<string, mixed> $plan */
    public static function show(Command $command, array $plan): void
    {
        $command->info('Schema Align plan');
        $binding = $plan['binding'] ?? [];
        $command->line('Target: '.self::plain(($binding['environment'] ?? '').' / '.($binding['database'] ?? '').' @ '.($binding['host'] ?? '').':'.($binding['port'] ?? '')));
        $command->line('Account: '.self::plain((string) ($binding['username'] ?? '')));
        $command->line('Plan: '.self::plain((string) ($plan['id'] ?? '')));
        foreach ($plan['warnings'] ?? [] as $warning) {
            // Keep old signed plans intact without displaying their superseded account policy as current.
            $obsolete = ($plan['engine'] ?? 'pgsql') === 'mysql' ? [
                'Apply is unavailable with global write, administrative or delegation privileges. Use permitted privileges and issue a new plan.',
                'Target connection has MySQL global administrative privileges. Inspection is read-only; apply is refused. Use a database-scoped role and regenerate its plan.',
            ] : [
                'Apply is unavailable for PostgreSQL superusers. Use a non-superuser connection and issue a new plan.',
                'Target connection uses a PostgreSQL superuser. Inspection is read-only; apply is refused. Use a restricted role and regenerate the plan before applying.',
            ];

            if (in_array($warning, $obsolete, true)) {
                $warning = 'This plan contains an obsolete account restriction. Approved apply with this account is now allowed.';
            }
            $command->warn(self::plain($warning));
        }
        $operations = $plan['operations'] ?? null;

        if (! is_array($operations) || $operations === []) {
            throw new RuntimeException('The signed plan contains no operations to review.');
        }
        $command->line('Changes: '.count($operations).' automatic operation(s)');
        foreach ($operations as $index => $operation) {
            $command->line(($index + 1).'. '.self::plain(($operation['kind'] ?? '').' '.($operation['table'] ?? '').' '.($operation['name'] ?? '')));
            $command->line('   '.self::plain((string) ($operation['id'] ?? '')));
            foreach ($operation['sql'] ?? [] as $sql) {
                $command->line('   '.self::plain($sql));
            }
        }
        $command->newLine();
    }

    private static function plain(string $value): string
    {
        // Escape terminal markup and control sequences without altering the executed SQL.
        return OutputFormatter::escape((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $value));
    }
}
