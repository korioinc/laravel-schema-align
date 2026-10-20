<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign;

use Illuminate\Support\ServiceProvider;
use Korioinc\SchemaAlign\Console\Commands\SchemaAlignCommand;

class SchemaAlignServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-schema-align.php', 'laravel-schema-align');

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
            __DIR__.'/../config/laravel-schema-align.php' => config_path('laravel-schema-align.php'),
        ], ['laravel-schema-align', 'laravel-schema-align-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-schema-align', 'laravel-schema-align-migrations']);

        $this->commands([
            SchemaAlignCommand::class,
        ]);
    }
}
