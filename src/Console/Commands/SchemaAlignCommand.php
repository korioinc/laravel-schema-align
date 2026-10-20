<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Console\Commands;

use Illuminate\Console\Command;

class SchemaAlignCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-schema-align:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-schema-align.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('SchemaAlign placeholder command executed.');

        return self::SUCCESS;
    }
}
