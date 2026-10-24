<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Korioinc\SchemaAlign\Console\PlanPreview;
use Korioinc\SchemaAlign\SchemaAlign;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ApplyCommand extends Command
{
    protected $signature = 'schema-align:apply {plan? : Optional saved plan ID; defaults to the latest signed plan} {--auto-approve : Explicitly apply without an interactive confirmation} {--approve= : Legacy exact signature approval} {--recover : Resume only an independently verified baseline or checkpoint} {--status : Read a saved execution status without connecting to the database} {--timeout=0 : PostgreSQL statement execution limit in seconds; 0 is unlimited and required for MySQL DDL} {--json : Emit machine-readable output}';

    protected $description = 'Review the latest plan and confirm application, or inspect its execution status';

    protected $help = <<<'HELP'
Application command. This can change original database objects.
Run schema-align:plan, then schema-align:apply and enter exactly yes to confirm.
No plan ID or signature copying is needed. If no plan exists, apply first creates an all-safe plan.
A saved plan is never silently regenerated when stale, invalid or bound to another target.
Before showing SQL, apply verifies the plan against the live database.
An already-applied plan exits without SQL, confirmation or replay.
After verified completion, inactive replay resources owned by this target and scope are removed.
Cleanup refusal reports applied_cleanup_blocked; committed original changes are not rolled back.
Run schema-align:plan after changing migrations; saved plans are not current-source comparisons.

Use --auto-approve for explicitly approved automation, including --no-interaction.
Legacy PLAN_ID --approve=SIGNATURE remains supported. Approval modes cannot be combined.
--status requires a saved plan and never generates one or connects to the database.
--recover resumes only an approved verified baseline or MySQL checkpoint.
Apply has no default PHP or SQL execution deadline, including when old published configuration says 30 seconds.
--timeout=N sets a PostgreSQL per-statement limit in seconds; 0 is unlimited, not a whole-command deadline.
MySQL DDL requires --timeout=0. Lock wait, replay and cleanup limits are independent and unchanged.
External process managers, database proxies, connection failures or server policies can still interrupt apply.
PostgreSQL superusers and MySQL administrators can apply approved plans.
Signature, target, identity, baseline and metadata visibility checks remain mandatory.
MySQL DDL commits per statement. No universal rollback or server DDL cancellation is promised.
HELP;

    public function handle(SchemaAlign $align): int
    {
        try {
            $id = $this->input->getArgument('plan');
            $approval = $this->input->getOption('approve');
            $auto = (bool) $this->option('auto-approve');
            $recover = (bool) $this->option('recover');

            if (($id !== null && ! is_string($id)) || ($approval !== null && ! is_string($approval))) {
                throw new InvalidArgumentException('Plan ID and approval must be strings.');
            }

            if ($auto && $approval !== null) {
                throw new InvalidArgumentException('Use either --auto-approve or --approve, not both.');
            }

            if ($this->option('status')) {
                if ($auto || $approval !== null || $recover) {
                    throw new InvalidArgumentException('--status cannot be combined with approval or recovery.');
                }
                $store = $align->store();
                $plan = $id === null ? $store->latest() : $store->load($id);

                if ($plan === null) {
                    throw new RuntimeException('No saved plan. Run schema-align:plan first.');
                }
                $this->output->writeln(json_encode($store->status($plan['id']), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

                return 0;
            }

            $timeout = $this->option('timeout');

            if (! is_string($timeout) || ! preg_match('/\A[0-9]{1,7}\z/D', $timeout) || (int) $timeout > 2147483) {
                throw new InvalidArgumentException('--timeout must be an integer between 0 and 2147483 seconds; 0 is unlimited.');
            }
            $statementTimeout = (int) $timeout * 1000;

            if ((int) ini_get('max_execution_time') > 0) {
                if (! function_exists('set_time_limit') || ! set_time_limit(0)) {
                    throw new RuntimeException('Cannot disable the PHP execution limit. Run php -d max_execution_time=0 artisan schema-align:apply.');
                }
            }

            $store = $align->store();
            $plan = $id === null ? $store->latest() : $store->load($id);
            $needsApproval = ! $auto && $approval === null && (! $this->input->isInteractive() || $this->option('json'));

            if ($plan === null) {
                if ($recover) {
                    throw new RuntimeException('Recovery requires an existing saved plan.');
                }

                if ($needsApproval) {
                    throw new RuntimeException('Interactive confirmation is required. Use --auto-approve for automation.');
                }
                $plan = $align->plan(['all-safe']);
            }

            if (! is_string($plan['id'] ?? null) || ! is_string($plan['signature'] ?? null)) {
                throw new RuntimeException('Invalid saved plan approval identity.');
            }

            if (($plan['engine'] ?? 'pgsql') === 'mysql' && $statementTimeout !== 0) {
                throw new InvalidArgumentException('MySQL DDL has no statement execution timeout. Use --timeout=0.');
            }

            if ($approval !== null && ! hash_equals($plan['signature'], $approval)) {
                throw new RuntimeException('Approval must equal the reviewed plan signature.');
            }
            $review = $align->reviewPlan($plan['id'], $recover);

            if ($review === null || ! hash_equals($plan['signature'], $review['plan']['signature'])) {
                throw new RuntimeException('The reviewed plan changed. Run schema-align:plan again.');
            }

            if ($review['status'] === 'already_applied') {
                $result = $align->finishApply($plan['id'], $plan['signature']);
                $blocked = $result['status'] === 'applied_cleanup_blocked';

                if ($this->option('json')) {
                    $result['status'] = $blocked ? 'applied_cleanup_blocked' : 'already_applied';
                    $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
                } else {
                    $this->info('Already applied. Verified against the current database; no original SQL was executed.');
                    $this->showCleanup($result['cleanup']);
                    $this->line('Run php artisan schema-align:plan to compare new migration changes.');
                }

                return $blocked ? 1 : 0;
            }

            if ($needsApproval) {
                throw new RuntimeException('Interactive confirmation is required. Use --auto-approve for automation.');
            }

            if (! $this->option('json')) {
                $preview = $plan;
                $preview['operations'] = array_slice($plan['operations'], $review['completed_operations']);

                if ($review['completed_operations'] > 0) {
                    $this->line('Recovery: skipping '.$review['completed_operations'].' verified completed operation(s).');
                }
                PlanPreview::show($this, $preview);
            }

            if ($approval === null && ! $auto) {
                $this->warn('This will execute the displayed SQL against the target database.');

                if ($this->ask('Enter yes to apply this plan') !== 'yes') {
                    $this->line('Apply cancelled. Original objects were not changed.');

                    return 1;
                }
            }
            // Pin the displayed identity/signature even if another process publishes a newer plan.
            $result = $align->apply($plan['id'], $plan['signature'], $recover, $statementTimeout);

            if ($this->option('json')) {
                $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                $this->info('Apply complete: '.($result['apply_status'] ?? $result['status'] ?? 'completed'));
                $this->showCleanup($result['cleanup'] ?? []);
            }

            return $result['status'] === 'applied_cleanup_blocked' ? 1 : 0;
        } catch (Throwable $error) {
            if ($this->option('json')) {
                $this->output->writeln(json_encode(['status' => 'failure', 'errors' => [$error->getMessage()]], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                $this->error($error->getMessage());
            }

            return 1;
        }
    }

    /** @param array<string, mixed> $cleanup */
    private function showCleanup(array $cleanup): void
    {
        if (($cleanup['status'] ?? null) === 'cleanup_blocked') {
            $this->error('Original changes are applied, but temporary cleanup is blocked. Retry schema-align:apply; original SQL will not rerun.');
            foreach ($cleanup['errors'] ?? [] as $error) {
                $this->line(OutputFormatter::escape((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $error)));
            }
            foreach ($cleanup['runs'] ?? [] as $run) {
                if (($run['status'] ?? null) === 'cleanup_blocked') {
                    $this->line(OutputFormatter::escape(json_encode($run, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
                }
            }
        } elseif (($cleanup['runs'] ?? []) !== []) {
            $this->line('Temporary replay resources cleaned: '.count($cleanup['runs']).' run(s).');
        }
    }
}
