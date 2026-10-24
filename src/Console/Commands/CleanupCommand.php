<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Console\Commands;

use Illuminate\Console\Command;
use Korioinc\SchemaAlign\SchemaAlign;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CleanupCommand extends Command
{
    protected $signature = 'schema-align:cleanup {run : Exact full replay run ID} {--json : Emit machine-readable result}';

    protected $description = 'Remove only owned, inactive temporary namespaces of an exact replay run';

    public function handle(SchemaAlign $align): int
    {
        try {
            $run = $this->input->getArgument('run');

            if (! is_string($run)) {
                throw new RuntimeException('An exact replay run ID is required.');
            }
            $result = $align->cleanup($run);
            $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return 0;
        } catch (Throwable $error) {
            $this->output->writeln(json_encode(['status' => 'failure', 'errors' => [$error->getMessage()]], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return 1;
        }
    }
}
