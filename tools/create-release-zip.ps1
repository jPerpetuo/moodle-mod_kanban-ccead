[CmdletBinding()]
param(
    [string]$Ref = 'HEAD',
    [string]$OutputDirectory = (Join-Path $PSScriptRoot '..\dist')
)

$ErrorActionPreference = 'Stop'

$reporoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$versionref = $Ref + ':version.php'
$versionfile = & git -C $reporoot show $versionref
if ($LASTEXITCODE -ne 0) {
    throw "Cannot read version.php from Git reference '$Ref'."
}

if ($versionfile -notmatch '\$plugin->release\s*=\s*''([^'']+)'';') {
    throw 'Cannot determine the plugin release from version.php.'
}

$release = $matches[1]
[System.IO.Directory]::CreateDirectory($OutputDirectory) | Out-Null
$outputpath = Join-Path $OutputDirectory "moodle-mod_kanban-$release.zip"

if (Test-Path -LiteralPath $outputpath) {
    throw "Release ZIP already exists: $outputpath"
}

& git -C $reporoot archive --format=zip '--prefix=kanban/' "--output=$outputpath" $Ref
if ($LASTEXITCODE -ne 0) {
    throw 'git archive failed.'
}

Write-Output $outputpath
