# build_portable_full.ps1
# Construye el paquete "todo incluido": codigo + runtimes portables en un solo ZIP.
# Modo CI  : ./build_portable_full.ps1 -Version 1.8.16 -OutputDir <dir>
#            (descarga php74/php82/python38/python311/VC_redist desde fuentes oficiales)
# Modo local: ./build_portable_full.ps1 -Version 1.8.16 -OutputDir <dir> -RuntimeSourceDir <copia_completa>
# Salida: AS400_Portable_Libre_v<Version>_TODO_INCLUIDO.zip (+ .sha256)

param(
    [Parameter(Mandatory = $true)][string]$Version,
    [string]$OutputDir,
    [string]$RuntimeSourceDir
)

$ErrorActionPreference = 'Stop'

if (-not $OutputDir) { $OutputDir = Join-Path $env:TEMP 'as400_full_build' }
New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null

$repoRoot = (git rev-parse --show-toplevel).Trim()
if (-not $repoRoot) { throw 'No hay un repositorio git (debe ejecutarse desde el checkout).' }

$stage = Join-Path $OutputDir 'stage_full'
if (Test-Path $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
New-Item -ItemType Directory -Path $stage -Force | Out-Null

Write-Host '[1/4] Copiando codigo (archivos versionados)...'
$tracked = @(& git ls-files)
foreach ($f in $tracked) {
    $src = Join-Path $repoRoot ($f -replace '/', '\')
    $dst = Join-Path $stage ($f -replace '/', '\')
    if (Test-Path -LiteralPath $src -PathType Leaf) {
        New-Item -ItemType Directory -Path (Split-Path $dst -Parent) -Force | Out-Null
        Copy-Item -LiteralPath $src -Destination $dst -Force
    }
}

# Carpetas de trabajo que la app/el updater esperan (se crean vacias)
foreach ($d in @('cache', 'backups', 'uploads', 'exports')) {
    New-Item -ItemType Directory -Path (Join-Path $stage $d) -Force | Out-Null
}

$runtimeDirs = @('php74', 'php82', 'python311', 'python38', 'redist')
if ($RuntimeSourceDir) {
    Write-Host "[2/4] Copiando runtimes desde $RuntimeSourceDir ..."
    foreach ($d in $runtimeDirs) {
        $src = Join-Path $RuntimeSourceDir $d
        if (Test-Path $src) {
            Copy-Item -Recurse -Force $src (Join-Path $stage $d)
        } else {
            Write-Warning "No se encontro $d en la fuente; se omitira."
        }
    }
} else {
    Write-Host '[2/4] Descargando runtimes desde fuentes oficiales...'
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $temp = Join-Path $OutputDir 'runtime_download'
    if (Test-Path $temp) { Remove-Item -Recurse -Force $temp }
    New-Item -ItemType Directory -Path $temp -Force | Out-Null

    $downloads = @(
        @{ url = 'https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x86.zip';      dest = 'php74.zip' },
        @{ url = 'https://windows.php.net/downloads/releases/archives/php-8.2.12-nts-Win32-vs16-x64.zip';      dest = 'php82.zip' },
        @{ url = 'https://www.python.org/ftp/python/3.8.10/python-3.8.10-embed-win32.zip';                    dest = 'python38.zip' },
        @{ url = 'https://www.python.org/ftp/python/3.11.8/python-3.11.8-embed-amd64.zip';                    dest = 'python311.zip' },
        @{ url = 'https://aka.ms/vs/17/release/vc_redist.x86.exe';                                             dest = 'vc_redist.x86.exe' },
        @{ url = 'https://aka.ms/vs/17/release/vc_redist.x64.exe';                                             dest = 'vc_redist.x64.exe' }
    )
    $wc = New-Object System.Net.WebClient
    $wc.Headers.Add('User-Agent', 'Mozilla/5.0')
    foreach ($d in $downloads) {
        $local = Join-Path $temp $d.dest
        Write-Host "   Descargando $($d.dest)..."
        $wc.DownloadFile($d.url, $local)
        if ((Get-Item $local).Length -lt 1000) { throw "Descarga invalida: $($d.dest)" }
    }
    foreach ($d in @('php74', 'php82', 'python38', 'python311')) {
        $zip = Join-Path $temp "$d.zip"
        $target = Join-Path $stage $d
        if (Test-Path $target) { Remove-Item -Recurse -Force $target }
        [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $target)
    }
    New-Item -ItemType Directory -Path (Join-Path $stage 'redist') -Force | Out-Null
    Copy-Item (Join-Path $temp 'vc_redist.x86.exe') (Join-Path $stage 'redist\vc_redist.x86.exe') -Force
    Copy-Item (Join-Path $temp 'vc_redist.x64.exe') (Join-Path $stage 'redist\vc_redist.x64.exe') -Force

    function New-PhpIni($dir, $engine, $extensions) {
        $production = Join-Path $dir 'php.ini-production'
        $target = Join-Path $dir 'php.ini'
        if (Test-Path $production) {
            $ini = Get-Content $production
            $ini = $ini -replace ';extension_dir = "ext"', "extension_dir = `"$engine\ext`""
            foreach ($e in $extensions) { $ini = $ini -replace ";extension=$e", "extension=$e" }
            $ini = $ini -replace ';log_errors = On', 'log_errors = On'
            $ini = $ini -replace ';cli_server.color = On', 'cli_server.color = On'
            Set-Content -Path $target -Value $ini -Encoding ASCII
        } else {
            $lines = @("extension_dir = `"$engine\ext`"", 'log_errors = On', 'display_errors = Off',
                       'memory_limit = 128M', 'upload_max_filesize = 2M', 'post_max_size = 8M',
                       'max_execution_time = 30', 'date.timezone = America/Mexico_City')
            foreach ($e in $extensions) { $lines += "extension=$e" }
            Set-Content -Path $target -Value $lines -Encoding ASCII
        }
    }
    New-PhpIni (Join-Path $stage 'php74') 'php74' @('curl', 'ftp', 'fileinfo', 'gd2', 'mbstring', 'openssl')
    New-PhpIni (Join-Path $stage 'php82') 'php82' @('curl', 'ftp', 'fileinfo', 'gd', 'mbstring', 'openssl', 'zip')
    Remove-Item -Recurse -Force $temp
}

Write-Host '[3/4] Empaquetando ZIP...'
$zipName = "AS400_Portable_Libre_v$Version" + '_TODO_INCLUIDO.zip'
$zipPath = Join-Path $OutputDir $zipName
if (Test-Path $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
& tar -a -c -f $zipPath -C $stage .
if ($LASTEXITCODE -ne 0) { throw 'Fallo al crear el ZIP (tar).' }

$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath "$zipPath.sha256" -Value $hash -NoNewline -Encoding ascii

Remove-Item -LiteralPath $stage -Recurse -Force

Write-Output "ZIP=$zipPath"
Write-Output "SIZE_MB=$([Math]::Round((Get-Item $zipPath).Length / 1MB, 1))"
Write-Output "SHA256=$hash"
