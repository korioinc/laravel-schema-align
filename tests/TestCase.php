<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Tests;

use Korioinc\SchemaAlign\SchemaAlignServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SchemaAlignServiceProvider::class,
        ];
    }
}
