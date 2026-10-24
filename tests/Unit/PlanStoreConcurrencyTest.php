<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Planning\PlanStore;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $directory = $this->planRaceRoot = sys_get_temp_dir().'/schema-align-key-race-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);

    if (PHP_OS_FAMILY === 'Windows') {
        (new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
$acl = Get-Acl -LiteralPath $env:SCHEMA_ALIGN_PLAN_DIRECTORY
$acl.SetAccessRuleProtection($true, $false)
$rule = New-Object System.Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
$acl.AddAccessRule($rule)
Set-Acl -LiteralPath $env:SCHEMA_ALIGN_PLAN_DIRECTORY -AclObject $acl
POWERSHELL], env: ['SCHEMA_ALIGN_PLAN_DIRECTORY' => $directory]))->mustRun();
    }
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->planRaceRoot);
});

it('preserves both signed plans when their first saves overlap key initialization', function (): void {
    $directory = $this->planRaceRoot;

    // Pause the real creator at key generation. The second process reports its
    // lock attempt, or captures the prematurely published key on the old code.
    $worker = <<<'PHP'
namespace Korioinc\SchemaAlign\Planning;

function random_bytes(int $length): string
{
    if ($length === 32 && $GLOBALS['argv'][3] === 'first') {
        \fwrite(STDOUT, "CREATING\n");
        if (\fgets(STDIN) !== "continue\n") {
            throw new \RuntimeException('Creator was not released.');
        }
    }

    return \random_bytes($length);
}

function fopen(string $filename, string $mode): mixed
{
    $handle = \fopen($filename, $mode);
    if (\basename($filename) === '.key.lock' && $GLOBALS['argv'][3] === 'second') {
        \fwrite(STDOUT, "ATTEMPT\n");
    }

    return $handle;
}

function file_get_contents(string $filename): string|false
{
    $contents = \file_get_contents($filename);
    if (\basename($filename) === '.key' && $GLOBALS['argv'][3] === 'second') {
        \fwrite(STDOUT, "ATTEMPT\n");
    }

    return $contents;
}

require $argv[1];
$store = new PlanStore($argv[2]);
$plan = $store->save(['writer' => $argv[3]]);
if ($store->load($plan['id']) != $plan) {
    throw new \RuntimeException('Saved plan failed its integrity check.');
}
echo 'SAVED:'.\json_encode($plan, JSON_THROW_ON_ERROR)."\n";
PHP;

    $input = new InputStream;
    $first = new Process([PHP_BINARY, '-r', $worker, dirname(__DIR__, 2).'/vendor/autoload.php', $directory, 'first'], input: $input, timeout: 20);
    $second = new Process([PHP_BINARY, '-r', $worker, dirname(__DIR__, 2).'/vendor/autoload.php', $directory, 'second'], timeout: 20);

    try {
        $first->start();
        expect($first->waitUntil(fn (): bool => str_contains($first->getOutput(), "CREATING\n")))->toBeTrue($first->getErrorOutput());
        $second->start();
        expect($second->waitUntil(fn (): bool => str_contains($second->getOutput(), "ATTEMPT\n")))->toBeTrue($second->getErrorOutput());
        $input->write("continue\n");
        $input->close();
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $store = new PlanStore($directory);
        $plans = [];
        foreach ([$first, $second] as $process) {
            expect(preg_match('/SAVED:(.+)/', $process->getOutput(), $matches))->toBe(1);
            $plan = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            expect($store->load($plan['id']))->toEqual($plan)
                ->and($store->status($plan['id'])['status'])->toBe('planned');
            $plans[] = $plan['id'];
        }
        expect($plans[0])->not->toBe($plans[1])
            ->and($store->latest()['id'])->toBeIn($plans);
    } finally {
        $input->close();
        $first->stop(0);
        $second->stop(0);
    }
});

it('preserves concurrent first saves when the plan directory does not exist', function (): void {
    $directory = $this->planRaceRoot.'/plans';
    $worker = <<<'PHP'
namespace Korioinc\SchemaAlign\Planning;

function is_dir(string $path): bool
{
    $exists = \is_dir($path);
    if ($path === $GLOBALS['argv'][2] && ! $exists) {
        \fwrite(STDOUT, "DIRECTORY_MISSING\n");
        if (\fgets(STDIN) !== "continue\n") {
            throw new \RuntimeException('Directory creator was not released.');
        }
    }

    return $exists;
}

require $argv[1];
$store = new PlanStore($argv[2]);
$plan = $store->save(['writer' => $argv[3]]);
echo 'SAVED:'.\json_encode($plan, JSON_THROW_ON_ERROR)."\n";
PHP;
    $inputs = [new InputStream, new InputStream];
    $processes = [];

    try {
        foreach ($inputs as $index => $input) {
            $process = new Process([PHP_BINARY, '-r', $worker, dirname(__DIR__, 2).'/vendor/autoload.php', $directory, (string) $index], input: $input, timeout: 20);
            $processes[] = $process;
            $process->start();
            expect($process->waitUntil(fn (): bool => str_contains($process->getOutput(), "DIRECTORY_MISSING\n")))->toBeTrue($process->getErrorOutput());
        }

        $store = new PlanStore($directory);
        $plans = [];
        foreach ($processes as $index => $process) {
            $inputs[$index]->write("continue\n");
            $inputs[$index]->close();
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
                ->and($process->getErrorOutput())->toBe('');
            expect(preg_match('/SAVED:(.+)/', $process->getOutput(), $matches))->toBe(1);
            $plan = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            expect($store->load($plan['id']))->toEqual($plan);
            $plans[] = $plan['id'];
        }
        expect($plans[0])->not->toBe($plans[1])
            ->and($store->latest()['id'])->toBe($plans[1]);
    } finally {
        foreach ($inputs as $input) {
            $input->close();
        }
        foreach ($processes as $process) {
            $process->stop(0);
        }
    }
});

it('refuses a symlink used as the signing key lock', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symlink creation requires privileges on Windows.');
    }
    $directory = $this->planRaceRoot;
    $store = new PlanStore($directory);

    try {
        $plan = $store->save(['sql' => 'CREATE TABLE example (id INT)']);
        $key = file_get_contents($directory.'/.key');
        rename($directory.'/.key.lock', $directory.'/.key.lock.original');
        symlink($directory.'/.key.lock.original', $directory.'/.key.lock');

        expect(fn () => $store->save(['sql' => 'DROP TABLE example']))->toThrow(RuntimeException::class, 'Signing key lock must not be a symlink.')
            ->and(fn () => $store->load($plan['id']))->toThrow(RuntimeException::class, 'Signing key lock must not be a symlink.')
            ->and(file_get_contents($directory.'/.key'))->toBe($key);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});
