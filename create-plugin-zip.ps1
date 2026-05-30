#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Build a Linux-compatible installer zip for this WordPress plugin.

.DESCRIPTION
    Reads the plugin slug from the containing folder name and the version from
    the main plugin file's `Version:` header. Writes the zip to ./plugin/
    using forward-slash paths so Linux/macOS unzip produces a single
    plugin-named folder.

    Excludes (per project convention):
      - Hidden files/folders (.git, .claude, .gitignore, etc.)
      - Folders starting with src- or svelte-
      - node_modules/, plugin/ (the output dir), this script
      - .md, .log files
      - Build configs (vite/tsconfig/svelte.config), package*.json, composer.lock
      - Test configs (phpcs.xml, phpunit.xml)

    Keeps:
      - vendor/ (Composer dependencies committed with the plugin)
      - All production PHP, JS, CSS, asset files
      - composer.json (for reference)
#>

$ErrorActionPreference = 'Stop'

$pluginDir  = $PSScriptRoot
$pluginSlug = Split-Path $pluginDir -Leaf
$mainFile   = Join-Path $pluginDir "$pluginSlug.php"

if (-not (Test-Path $mainFile)) {
    Write-Error "Main plugin file not found: $mainFile"
    exit 1
}

$header = Get-Content $mainFile -Raw
if ($header -notmatch '(?im)^\s*\*?\s*Version:\s*([\d.]+)') {
    Write-Error "Could not parse Version: header from $mainFile"
    exit 1
}
$version = $matches[1]

$outputDir = Join-Path $pluginDir 'plugin'
if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

# Wipe any prior zips for this plugin (keep the dir).
Get-ChildItem $outputDir -Filter "$pluginSlug-*.zip" -ErrorAction SilentlyContinue | Remove-Item -Force

$zipName = "$pluginSlug-$version.zip"
$zipPath = Join-Path $outputDir $zipName

function Test-ShouldInclude {
    param([string]$RelPath)

    # Forward-slash for matching
    $rel = $RelPath -replace '\\', '/'

    # Reject if ANY path segment is excluded.
    foreach ($part in $rel -split '/') {
        if ($part -eq '') { continue }
        if ($part.StartsWith('.'))        { return $false }   # hidden
        if ($part.StartsWith('src-'))     { return $false }   # src-svelte etc.
        if ($part.StartsWith('svelte-'))  { return $false }   # svelte-app etc.
        if ($part -eq 'node_modules')     { return $false }
        if ($part -eq 'plugin')           { return $false }
    }

    $leaf = Split-Path $rel -Leaf
    switch -Wildcard ($leaf) {
        '*.md'                  { return $false }
        '*.log'                 { return $false }
        'vite.config.*'         { return $false }
        'tsconfig.*'            { return $false }
        'svelte.config.*'       { return $false }
        'package.json'          { return $false }
        'package-lock.json'     { return $false }
        'composer.lock'         { return $false }
        'phpcs.xml'             { return $false }
        'phpunit.xml'           { return $false }
        'create-plugin-zip.ps1' { return $false }
    }

    return $true
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# Create archive (overwrites if Remove-Item above missed).
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
$zip = [System.IO.Compression.ZipFile]::Open(
    $zipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
)

$included = 0
try {
    $files = Get-ChildItem -Path $pluginDir -Recurse -File -Force
    foreach ($file in $files) {
        $rel = $file.FullName.Substring($pluginDir.Length).TrimStart('\','/')
        if (-not (Test-ShouldInclude $rel)) { continue }

        # Single top-level folder = plugin slug; forward slashes throughout.
        $entryName = "$pluginSlug/" + ($rel -replace '\\', '/')

        $entry  = $zip.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
        $reader = [System.IO.File]::OpenRead($file.FullName)
        $writer = $entry.Open()
        try {
            $reader.CopyTo($writer)
        } finally {
            $writer.Dispose()
            $reader.Dispose()
        }
        $included++
    }
} finally {
    $zip.Dispose()
}

$sizeKB = [math]::Round((Get-Item $zipPath).Length / 1024, 1)
Write-Host "Created: $zipPath"
Write-Host "Files:   $included"
Write-Host "Size:    ${sizeKB} KB"
