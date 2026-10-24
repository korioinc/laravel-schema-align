<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Replay\ReplayWorkspace;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->workspaceDirectory = sys_get_temp_dir().'/schema-align-workspace-test-'.bin2hex(random_bytes(12));
    mkdir($this->workspaceDirectory, 0700);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->workspaceDirectory);
});

/** @param array<string, string> $environment */
function workspaceWithWindowsPrivacy(string $temporary, bool $refuse, array $environment = []): Process
{
    $worker = <<<'PHP'
namespace Korioinc\SchemaAlign\Replay;

const PHP_OS_FAMILY = 'Windows';

function sys_get_temp_dir(): string { return $GLOBALS['argv'][2]; }

require $argv[1];
try {
    $workspace = new ReplayWorkspace;
} catch (\RuntimeException $error) {
    if ($argv[3] !== 'refuse') { throw $error; }
    return;
}
if ($argv[3] === 'refuse') {
    $workspace->remove();
    throw new \LogicException('Replay accepted an unverified private directory.');
}
echo $workspace->path;
PHP;

    return new Process([PHP_BINARY, '-r', $worker, dirname(__DIR__, 2).'/vendor/autoload.php', $temporary, $refuse ? 'refuse' : 'allow'], env: $environment);
}

it('refuses private worker storage when Windows ACL verification cannot run', function (): void {
    // An invalid executable makes the external verifier fail on every host OS.
    file_put_contents($this->workspaceDirectory.'/powershell.exe', 'invalid executable');
    chmod($this->workspaceDirectory.'/powershell.exe', 0700);
    $process = workspaceWithWindowsPrivacy($this->workspaceDirectory, true, [
        'PATH' => $this->workspaceDirectory, 'Path' => $this->workspaceDirectory,
    ]);

    expect(fn () => $process->mustRun())->not->toThrow(Throwable::class);
});

if (PHP_OS_FAMILY === 'Windows') {
    it('only writes private worker files under an ACL that protects descendants', function (string $access): void {
        (new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$me = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
$acl = New-Object System.Security.AccessControl.DirectorySecurity
$acl.SetOwner($me)
$acl.SetAccessRuleProtection($true, $false)
$inheritance = if ($env:SCHEMA_ALIGN_TEST_ACCESS -eq 'directories-only') { 'ContainerInherit' } else { 'ContainerInherit,ObjectInherit' }
$propagation = if ($env:SCHEMA_ALIGN_TEST_ACCESS -eq 'one-generation') { 'NoPropagateInherit' } else { 'None' }
$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule($me, 'FullControl', $inheritance, $propagation, 'Allow')))
if ($env:SCHEMA_ALIGN_TEST_ACCESS -eq 'shared') {
    $everyone = New-Object System.Security.Principal.SecurityIdentifier('S-1-1-0')
    $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule($everyone, 'ReadAndExecute', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
}
Set-Acl -LiteralPath $env:SCHEMA_ALIGN_TEST_DIRECTORY -AclObject $acl
POWERSHELL], env: ['SCHEMA_ALIGN_TEST_DIRECTORY' => $this->workspaceDirectory, 'SCHEMA_ALIGN_TEST_ACCESS' => $access]))->mustRun();
        $refuse = $access !== 'private';
        $process = workspaceWithWindowsPrivacy($this->workspaceDirectory, $refuse);

        expect(fn () => $process->mustRun())->not->toThrow(Throwable::class);

        if ($refuse) {
            return;
        }
        $root = $process->getOutput();
        mkdir($root.'/cache', 0700);
        file_put_contents($root.'/cache/private.php', '<?php return "private-config-sentinel";');
        $verification = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$acl = Get-Acl -LiteralPath $env:SCHEMA_ALIGN_TEST_FILE
$me = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$rules = @($acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]) | Where-Object { $_.AccessControlType -eq 'Allow' })
if ($rules.Count -eq 0) { throw 'Private file has no verifiable access rules' }
foreach ($rule in $rules) {
    if ($rule.IdentityReference.Value -ne $me) { throw 'Worker descendant permits another principal' }
}
POWERSHELL], env: ['SCHEMA_ALIGN_TEST_FILE' => $root.'/cache/private.php']);

        expect(fn () => $verification->mustRun())->not->toThrow(Throwable::class);
    })->with(['private', 'shared', 'directories-only', 'one-generation']);
}

it('preserves external data during repeated workspace cleanup with a nested symlink', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('This probe requires Unix symlink creation without elevated privileges.');
    }
    $workspace = new ReplayWorkspace;
    $target = $this->workspaceDirectory.'/unowned';
    mkdir($target);
    file_put_contents($target.'/sentinel.txt', 'preserved external data');
    symlink($target, $workspace->path.'/nested');

    try {
        $workspace->remove();
        $workspace->remove();

        expect(file_get_contents($target.'/sentinel.txt'))->toBe('preserved external data');
    } finally {
        $workspace->remove();
    }
});

it('preserves another directory when a workspace is replaced after its file status was cached', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('This probe requires Unix symlink creation without elevated privileges.');
    }
    $workspace = new ReplayWorkspace;
    $target = $this->workspaceDirectory.'/unowned';
    mkdir($target);
    file_put_contents($target.'/sentinel.txt', 'preserved external data');

    try {
        is_link($workspace->path);
        is_dir($workspace->path);
        (new Process([
            PHP_BINARY, '-r',
            'if (! rename($argv[1], $argv[1].".moved") || ! symlink($argv[2], $argv[1])) { throw new RuntimeException("Cannot replace disposable workspace."); }',
            $workspace->path, $target,
        ]))->mustRun();

        expect(fn () => $workspace->remove())->toThrow(RuntimeException::class)
            ->and(file_get_contents($target.'/sentinel.txt'))->toBe('preserved external data');
    } finally {
        clearstatcache(true, $workspace->path);

        if (is_link($workspace->path)) {
            unlink($workspace->path);
        }
        $workspace->remove();
        (new Filesystem)->deleteDirectory($workspace->path.'.moved');
    }
});
