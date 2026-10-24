<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Filesystem;

use RuntimeException;
use Symfony\Component\Process\Process;

final class WindowsDirectoryPrivacy
{
    public static function assertPrivate(string $directory, bool $inheritanceRequired = false): void
    {
        // Unix modes do not restrict NTFS ACLs. Verify the actual owner and grants.
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$root = Get-Item -LiteralPath $env:SCHEMA_ALIGN_PRIVATE_DIRECTORY -Force
if (-not $root.PSIsContainer) { throw 'Private storage must be a directory' }
$me = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$allowed = @($me, 'S-1-5-18', 'S-1-5-32-544')
$inheritance = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
$items = @($root) + @(Get-ChildItem -LiteralPath $root.FullName -Force)
foreach ($item in $items) {
    if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse points are not allowed' }
    $acl = Get-Acl -LiteralPath $item.FullName
    $owner = $acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value
    if ($allowed -notcontains $owner) { throw 'Private storage has an untrusted owner' }
    $inheritable = $false
    foreach ($rule in $acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) {
        if ($rule.AccessControlType -eq 'Allow') {
            if ($allowed -notcontains $rule.IdentityReference.Value) { throw 'Private storage grants access to another principal' }
            if (($rule.InheritanceFlags -band $inheritance) -eq $inheritance -and
                ($rule.PropagationFlags -band [System.Security.AccessControl.PropagationFlags]::NoPropagateInherit) -eq 0) {
                $inheritable = $true
            }
        }
    }
    if ($env:SCHEMA_ALIGN_REQUIRE_INHERITANCE -eq '1' -and $item.PSIsContainer -and -not $inheritable) {
        throw 'Private directory requires a trusted grant inherited by files and subdirectories without NoPropagateInherit'
    }
}
POWERSHELL;
        $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script], null, [
            'SCHEMA_ALIGN_PRIVATE_DIRECTORY' => $directory,
            'SCHEMA_ALIGN_REQUIRE_INHERITANCE' => $inheritanceRequired ? '1' : '0',
        ], null, 15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Private NTFS ACL required. Remove inherited/shared grants; allow only the current account, SYSTEM and Administrators. '.$process->getErrorOutput());
        }
    }
}
