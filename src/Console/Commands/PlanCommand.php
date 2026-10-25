<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Korioinc\SchemaAlign\Console\PlanPreview;
use Korioinc\SchemaAlign\SchemaAlign;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class PlanCommand extends Command
{
    protected $signature = 'schema-align:plan {--select=* : Operation IDs; defaults to all supported changes} {--json : Emit signed plan with SQL}';

    protected $description = 'Replay trusted migrations in temporary namespaces and pin selected SQL without changing original objects';

    protected $help = <<<'HELP'
Application command, not a package development test. Original objects are not changed.
Uses the same writable temporary namespace replay as schema-align:diff.
See php artisan help schema-align:diff for first-use requirements.

Omitting --select selects all supported operations, including reviewed PostgreSQL column removals.
Applying PostgreSQL column removals requires --allow-drop-columns.
Use repeated --select=ID to select only specific operations from diff.
Unsupported changes remain visible for manual review and are not executable.
This saves a signed plan only; it does not apply changes to original objects.
Review the displayed changes, then run schema-align:apply and enter yes.
The latest signed plan is selected automatically. Use --json for machine-readable plan data.
HELP;

    public function handle(SchemaAlign $align): int
    {
        try {
            $selection = $this->input->getOption('select');

            if (! is_array($selection) || ! array_is_list($selection)) {
                throw new InvalidArgumentException('Selection must be a list of operation IDs.');
            }
            foreach ($selection as $id) {
                if (! is_string($id)) {
                    throw new InvalidArgumentException('Each selected operation ID must be a string.');
                }
            }
            $plan = $align->plan($selection === [] ? ['all-supported'] : $selection);

            if ($this->option('json')) {
                $this->output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                PlanPreview::show($this, $plan);
                $this->info('Plan saved. Run php artisan schema-align:apply to review and confirm.');
            }

            return 0;
        } catch (Throwable $error) {
            if ($this->option('json')) {
                $this->output->writeln(json_encode(['status' => 'failure', 'errors' => [$error->getMessage()]], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                $this->error(OutputFormatter::escape((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $error->getMessage())));
            }

            return 1;
        }
    }
}
