param(
    [Parameter(Mandatory = $true)][string]$Version,
    [string]$OutputDir
)

$ErrorActionPreference = 'Stop'

if (-not $OutputDir) { $OutputDir = Join-Path $env:TEMP 'as400_update_build' }
New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null

$repoRoot = (git rev-parse --show-toplevel).Trim()
if (-not $repoRoot) { throw 'No hay un repositorio git (debe ejecutarse desde el checkout).' }

$prevTag = $null
$tags = @(& git tag --sort=-version:refname 2>$null)
if ($tags.Count -ge 2) { $prevTag = $tags[1] }

$removed = @()
if ($prevTag) {
    $changed = & git diff --name-status --diff-filter=D "$prevTag..HEAD" 2>$null
    if (-not $changed) { $changed = @() }
    foreach ($line in $changed) {
        $parts = $line -split "`t"
        if ($parts.Count -ge 2 -and $parts[-1]) { $removed += $parts[-1] }
    }
}
$removed = $removed | Sort-Object -Unique

$zipName = "update_v$Version.zip"
$zipPath = Join-Path $OutputDir $zipName
if (Test-Path $zipPath) { Remove-Item -LiteralPath $zipPath -Force }

$stage = Join-Path $OutputDir 'stage'
if (Test-Path $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
New-Item -ItemType Directory -Path $stage -Force | Out-Null

$tracked = @(& git ls-files)
foreach ($f in $tracked) {
    $src = Join-Path $repoRoot ($f -replace '/', '\')
    $dst = Join-Path $stage ($f -replace '/', '\')
    if (Test-Path -LiteralPath $src -PathType Leaf) {
        New-Item -ItemType Directory -Path (Split-Path $dst -Parent) -Force | Out-Null
        Copy-Item -LiteralPath $src -Destination $dst -Force
    }
}

$updaterDir = Join-Path $stage '_updater'
New-Item -ItemType Directory -Path $updaterDir -Force | Out-Null
Set-Content -LiteralPath (Join-Path $updaterDir 'remove.txt') -Value ($removed -join "`r`n") -NoNewline -Encoding utf8
Set-Content -LiteralPath (Join-Path $updaterDir 'version.txt') -Value $Version -NoNewline -Encoding utf8

$items = Get-ChildItem -LiteralPath $stage -Force | ForEach-Object { $_.FullName }
Compress-Archive -LiteralPath $items -DestinationPath $zipPath -CompressionLevel Optimal

$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath "$zipPath.sha256" -Value $hash -NoNewline -Encoding ascii

Remove-Item -LiteralPath $stage -Recurse -Force

Write-Output "ZIP=$zipPath"
Write-Output "SHA256=$hash"
Write-Output "FILES_IN_PACKAGE=$((& tar -tf $zipPath | Measure-Object -Line).Lines)"
