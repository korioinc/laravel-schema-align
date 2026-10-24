<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign;

use Illuminate\Support\ServiceProvider;
use Korioinc\SchemaAlign\Console\Commands\ApplyCommand;
use Korioinc\SchemaAlign\Console\Commands\CleanupCommand;
use Korioinc\SchemaAlign\Console\Commands\DiffCommand;
use Korioinc\SchemaAlign\Console\Commands\PlanCommand;

class SchemaAlignServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/schema-align.php', 'schema-align');

        $this->app->singleton(SchemaAlign::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/schema-align.php' => config_path('schema-align.php'),
        ], ['laravel-schema-align', 'laravel-schema-align-config']);

        $this->commands([
            DiffCommand::class,
            PlanCommand::class,
            ApplyCommand::class,
            CleanupCommand::class,
        ]);
    }
}
