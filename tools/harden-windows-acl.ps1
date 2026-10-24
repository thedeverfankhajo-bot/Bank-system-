[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'Medium')]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $DataDirectory,

    [Parameter()]
    [ValidateNotNullOrEmpty()]
    [string] $ServerAccount = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name,

    [Parameter()]
    [string[]] $BackupDirectory = @()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$icacls = Join-Path $env:SystemRoot 'System32\icacls.exe'
if (-not (Test-Path -LiteralPath $icacls -PathType Leaf)) {
    throw "icacls.exe was not found at $icacls"
}

# Resolve the service identity up front, so a typo cannot lock the server out.
try {
    $serverSid = ([System.Security.Principal.NTAccount]::new($ServerAccount)).Translate([System.Security.Principal.SecurityIdentifier]).Value
} catch {
    throw "Windows could not resolve the PocketMine service account '$ServerAccount'. Supply the exact account used by the service."
}

$allowedSids = @($serverSid, 'S-1-5-18', 'S-1-5-32-544')
$allowedAces = @(
    ('*' + $serverSid + ':(OI)(CI)F'),
    '*S-1-5-18:(OI)(CI)F',
    '*S-1-5-32-544:(OI)(CI)F'
)
$targets = @($DataDirectory) + @($BackupDirectory)

foreach ($target in $targets) {
    if ([string]::IsNullOrWhiteSpace($target)) {
        continue
    }

    $fullPath = [System.IO.Path]::GetFullPath($target)
    $root = [System.IO.Path]::GetPathRoot($fullPath)
    if ($fullPath.TrimEnd('\') -eq $root.TrimEnd('\')) {
        throw "Refusing to change ACLs on a drive root: $fullPath"
    }
    if (-not (Test-Path -LiteralPath $fullPath -PathType Container)) {
        throw "Directory does not exist. Create it first, then re-run the script: $fullPath"
    }

    $item = Get-Item -LiteralPath $fullPath -Force
    if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Refusing to change ACLs through a reparse point or directory junction: $fullPath"
    }

    $description = "Replace ACLs recursively on $fullPath; allow only the PocketMine account, SYSTEM and local Administrators."
    if (-not $PSCmdlet.ShouldProcess($fullPath, $description)) {
        continue
    }

    # Reset stale explicit entries first, then remove inherited access from every child.
    # Finally grant the minimum expected principals with full control. The account
    # must match the identity that actually runs the PocketMine process.
    & $icacls $fullPath '/reset' '/T' '/C' '/Q' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "icacls reset failed for $fullPath (exit code $LASTEXITCODE)."
    }

    & $icacls $fullPath '/inheritance:r' '/T' '/C' '/Q' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Could not disable inherited ACLs for $fullPath (exit code $LASTEXITCODE)."
    }

    & $icacls $fullPath '/grant:r' $allowedAces '/T' '/C' '/Q' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Could not assign restricted ACLs for $fullPath (exit code $LASTEXITCODE)."
    }

    # Verify the live ACL tree. Any unexpected Allow ACE fails the run rather than
    # reporting success while another local group can read account data or backups.
    $nodes = @($item) + @(Get-ChildItem -LiteralPath $fullPath -Force -Recurse -ErrorAction Stop)
    foreach ($node in $nodes) {
        $acl = Get-Acl -LiteralPath $node.FullName
        foreach ($rule in $acl.Access) {
            if ($rule.AccessControlType -ne [System.Security.AccessControl.AccessControlType]::Allow) {
                continue
            }

            try {
                $sid = $rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
            } catch {
                throw "Could not resolve an ACL identity on $($node.FullName)."
            }
            if ($sid -notin $allowedSids) {
                throw "Unexpected Allow ACL for SID $sid on $($node.FullName). Inspect and repair this path manually."
            }
        }
    }

    Write-Host "ACL verification passed: $fullPath"
}

Write-Host 'Completed. Verify the actual PocketMine service can read/write the database and can create protected backups.'
