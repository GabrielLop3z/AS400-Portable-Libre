# Instalar_Runtime_Oficial.ps1
# Fallback de Configurar_Entorno.bat: instala los componentes portables
# descargando desde fuentes oficiales (windows.php.net, python.org, Microsoft)
# cuando GitHub no es accesible. Compatible con PowerShell 2.0 (.NET 4.5+).
# Exit code: 0 = OK, 1 = fallo.

$ErrorActionPreference = 'Stop'
$ROOT = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $ROOT.EndsWith('\')) { $ROOT += '\' }

function Test-Complete {
    return ((Test-Path "$ROOT\php82\php.exe") -and (Test-Path "$ROOT\php74\php.exe") -and `
            (Test-Path "$ROOT\python311\python.exe") -and (Test-Path "$ROOT\python38\python.exe") -and `
            (Test-Path "$ROOT\redist\vc_redist.x64.exe") -and (Test-Path "$ROOT\redist\vc_redist.x86.exe"))
}

if (Test-Complete) { exit 0 }

Write-Host ""
Write-Host " [#] MODO FALLBACK: FUENTES OFICIALES (GitHub no disponible)."
Write-Host "     Descargando php74 / php82 / python38 / python311 / VC_redist..."
Write-Host "     Esto puede tardar varios minutos segun su conexion."
Write-Host ""

Add-Type -AssemblyName System.IO.Compression.FileSystem

$TEMP_DIR = Join-Path $env:TEMP 'AS400_Runtime_Oficial'
if (Test-Path $TEMP_DIR) { Remove-Item $TEMP_DIR -Recurse -Force }
New-Item -ItemType Directory -Path $TEMP_DIR | Out-Null

$downloads = @(
    @{ url = 'https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x86.zip';  dest = 'php74.zip' },
    @{ url = 'https://windows.php.net/downloads/releases/archives/php-8.2.12-nts-Win32-vs16-x64.zip';  dest = 'php82.zip' },
    @{ url = 'https://www.python.org/ftp/python/3.8.10/python-3.8.10-embed-win32.zip';               dest = 'python38.zip' },
    @{ url = 'https://www.python.org/ftp/python/3.11.8/python-3.11.8-embed-amd64.zip';               dest = 'python311.zip' },
    @{ url = 'https://aka.ms/vs/17/release/vc_redist.x86.exe';                                        dest = 'vc_redist.x86.exe' },
    @{ url = 'https://aka.ms/vs/17/release/vc_redist.x64.exe';                                        dest = 'vc_redist.x64.exe' }
)

$wc = New-Object System.Net.WebClient
$wc.Headers.Add('User-Agent', 'Mozilla/5.0')
foreach ($d in $downloads) {
    $local = Join-Path $TEMP_DIR $d.dest
    Write-Host " [$] Descargando $($d.dest)..."
    try {
        $wc.DownloadFile($d.url, $local)
    } catch {
        Write-Host " [!] Fallo al descargar $($d.dest): $($_.Exception.Message)"
        exit 1
    }
    if (-not (Test-Path $local) -or (Get-Item $local).Length -lt 1000) {
        Write-Host " [!] Descarga incompleta o invalida: $($d.dest)"
        exit 1
    }
}

function Extract-Zip($zip, $target) {
    if (Test-Path $target) { Remove-Item $target -Recurse -Force }
    [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $target)
}

Write-Host " [$] Extrayendo componentes..."
Extract-Zip (Join-Path $TEMP_DIR 'php74.zip')     "$ROOT\php74"
Extract-Zip (Join-Path $TEMP_DIR 'php82.zip')     "$ROOT\php82"
Extract-Zip (Join-Path $TEMP_DIR 'python38.zip')  "$ROOT\python38"
Extract-Zip (Join-Path $TEMP_DIR 'python311.zip') "$ROOT\python311"
if (-not (Test-Path "$ROOT\redist")) { New-Item -ItemType Directory -Path "$ROOT\redist" | Out-Null }
Copy-Item (Join-Path $TEMP_DIR 'vc_redist.x86.exe') "$ROOT\redist\vc_redist.x86.exe" -Force
Copy-Item (Join-Path $TEMP_DIR 'vc_redist.x64.exe') "$ROOT\redist\vc_redist.x64.exe" -Force

# Genera php.ini desde php.ini-production del motor (o minimo si faltara),
# replicando la configuracion de fabrica (extension_dir relativo + extensiones).
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

New-PhpIni "$ROOT\php74" 'php74' @('curl','ftp','fileinfo','gd2','mbstring','openssl')
New-PhpIni "$ROOT\php82" 'php82' @('curl','ftp','fileinfo','gd','mbstring','openssl','zip')

Remove-Item $TEMP_DIR -Recurse -Force -ErrorAction SilentlyContinue

if (Test-Complete) {
    Write-Host ""
    Write-Host " [+] ENTORNO PORTABLE INSTALADO CORRECTAMENTE (FUENTES OFICIALES)."
    Write-Host ""
    exit 0
}

Write-Host ""
Write-Host " [!] ERROR: LOS COMPONENTES NO QUEDARON COMPLETOS."
Write-Host ""
exit 1
