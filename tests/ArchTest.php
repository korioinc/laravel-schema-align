<?php

declare(strict_types=1);
use Illuminate\Console\Command;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Korioinc\SchemaAlign')
    ->toUseStrictTypes();

arch('the commands namespace contains only Artisan commands')
    ->expect('Korioinc\SchemaAlign\Console\Commands')
    ->toExtend(Command::class);
