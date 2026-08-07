Write-Host "Limpiando procesos previos..."
$null = taskkill /f /im php.exe /t 2>$null
$null = taskkill /f /im php-win.exe /t 2>$null
Start-Sleep -Seconds 2

Write-Host "Construyendo Carpeta Portátil Final..."
$portableDir = "C:\xampp\htdocs\Spool_Portable"

if (Test-Path $portableDir) { 
    Remove-Item -Recurse -Force $portableDir -ErrorAction SilentlyContinue 
    if (Test-Path $portableDir) {
        Write-Host "Forzando limpieza de remanentes..."
        Get-ChildItem $portableDir | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }
}

New-Item -ItemType Directory -Force -Path $portableDir | Out-Null
New-Item -ItemType Directory -Force -Path "$portableDir\php74" | Out-Null
New-Item -ItemType Directory -Force -Path "$portableDir\php82" | Out-Null
New-Item -ItemType Directory -Force -Path "$portableDir\python38" | Out-Null
New-Item -ItemType Directory -Force -Path "$portableDir\python311" | Out-Null
New-Item -ItemType Directory -Force -Path "$portableDir\exports" | Out-Null

$cacheDir = "C:\xampp\htdocs\AS400\cache"
if (!(Test-Path $cacheDir)) { New-Item -ItemType Directory -Path $cacheDir | Out-Null }

Write-Host "Descargando Entornos para Windows 7 (Legacy x86) y Windows 10 (Modern x64)..."
$php74Z = "$cacheDir\php74.zip"
$php82Z = "$cacheDir\php82.zip"
$py38Z = "$cacheDir\py38.zip"
$py311Z = "$cacheDir\py311.zip"

if (!(Test-Path $php74Z)) { Invoke-WebRequest "https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x86.zip" -OutFile $php74Z -UseBasicParsing }
if (!(Test-Path $php82Z)) { Invoke-WebRequest "https://windows.php.net/downloads/releases/archives/php-8.2.12-nts-Win32-vs16-x64.zip" -OutFile $php82Z -UseBasicParsing }
if (!(Test-Path $py38Z)) { Invoke-WebRequest "https://www.python.org/ftp/python/3.8.10/python-3.8.10-embed-win32.zip" -OutFile $py38Z -UseBasicParsing }
if (!(Test-Path $py311Z)) { Invoke-WebRequest "https://www.python.org/ftp/python/3.11.8/python-3.11.8-embed-amd64.zip" -OutFile $py311Z -UseBasicParsing }

Write-Host "Extrayendo..."
Expand-Archive -Path $php74Z -DestinationPath "$portableDir\php74" -Force
Expand-Archive -Path $php82Z -DestinationPath "$portableDir\php82" -Force
Expand-Archive -Path $py38Z -DestinationPath "$portableDir\python38" -Force
Expand-Archive -Path $py311Z -DestinationPath "$portableDir\python311" -Force

Write-Host "Configurando PHP.ini..."
foreach ($pDir in @("php74", "php82")) {
    $iniProd = "$portableDir\$pDir\php.ini-production"
    $iniPath = "$portableDir\$pDir\php.ini"
    if (Test-Path $iniProd) { Copy-Item $iniProd $iniPath -Force } else { New-Item -ItemType File -Path $iniPath -Force | Out-Null }
    (Get-Content $iniPath) `
        -replace ';extension_dir = "ext"', "extension_dir = `"$pDir\ext`"" `
        -replace ';extension=curl', 'extension=curl' `
        -replace ';extension=mbstring', 'extension=mbstring' `
        -replace ';extension=ftp', 'extension=ftp' `
        -replace ';extension=openssl', 'extension=openssl' `
        -replace ';extension=zip', 'extension=zip' `
        -replace ';extension=fileinfo', 'extension=fileinfo' `
        -replace ';extension=gd', 'extension=gd' `
        -replace ';log_errors = On', 'log_errors = On' | Set-Content $iniPath
}

Write-Host "Copiando Codigo Fuente a la Raiz..."
Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\app\*" "$portableDir\"
Copy-Item "C:\xampp\htdocs\AS400\app\assets\theme.css" "$portableDir\assets\"
Copy-Item "C:\xampp\htdocs\AS400\app\js\main.js" "$portableDir\js\"
Copy-Item "C:\xampp\htdocs\AS400\app\config\gatekeeper.json" "$portableDir\config\"

if (!(Test-Path "$portableDir\src")) { Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\src" "$portableDir\" }
if (!(Test-Path "$portableDir\vendor")) { Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\vendor" "$portableDir\" }
if (Test-Path "C:\xampp\htdocs\AS400\vendor74") { Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\vendor74" "$portableDir\" }

$vitalFiles = @("composer.json", "version.json", "favicon.svg")
foreach ($f in $vitalFiles) {
    if (Test-Path "C:\xampp\htdocs\AS400\$f") { Copy-Item "C:\xampp\htdocs\AS400\$f" "$portableDir\" }
}

Write-Host "Creando Lanzadores..."
$batContent = @"
@echo off
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
cd /d "%ROOT%"
title Lanzador Spool

echo ======================================================
echo    SPOOL EXPLORER - DETECCION DEL SISTEMA
echo ======================================================

set "OS_LEGACY=0"
ver | find "6.1" >nul && set "OS_LEGACY=1"
ver | find "6.0" >nul && set "OS_LEGACY=1"
ver | find "5.1" >nul && set "OS_LEGACY=1"

if "!OS_LEGACY!"=="1" (
    echo [INFO] Entorno Legacy detectado (Win 7/Vista/XP).
    echo [INFO] Cargando Motores Estables (PHP 7.4 x86 / Python 3.8 x86).
    set "PHP_DIR=php74"
    set "PY_DIR=python38"
) else (
    echo [INFO] Entorno Moderno detectado (Win 8/10/11).
    echo [INFO] Cargando Motores Optimizados (PHP 8.2 x64 / Python 3.11 x64).
    set "PHP_DIR=php82"
    set "PY_DIR=python311"
)

echo [1/3] Limpiando procesos anteriores...
taskkill /f /im php-win.exe /t >nul 2>&1
taskkill /f /im php.exe /t >nul 2>&1

echo [2/3] Iniciando motor de datos (%PHP_DIR%)...
if exist server_logs.txt del server_logs.txt
start /b "" "%PHP_DIR%\php.exe" -c "%PHP_DIR%\php.ini" -S 127.0.0.1:8181 > server_logs.txt 2>&1

echo [3/3] Verificando conexion...
set "URL=http://127.0.0.1:8181"
timeout /t 3 /nobreak > nul

netstat -ano | find "8181" | find "LISTENING" >nul
if %errorlevel% neq 0 (
    echo [ERROR] Reintentando motor secundario...
    start /min "Spool" "%PHP_DIR%\php-win.exe" -c "%PHP_DIR%\php.ini" -S 127.0.0.1:8181
    timeout /t 3 /nobreak > nul
)

echo [EXITO] Cargando aplicacion...
set "EDGE_X86=C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
set "EDGE_X64=C:\Program Files\Microsoft\Edge\Application\msedge.exe"
set "CHROME=C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"

if exist "%EDGE_X64%" (
    start /max "" "%EDGE_X64%" --app=%URL% --start-maximized
    exit
)
if exist "%EDGE_X86%" (
    start /max "" "%EDGE_X86%" --app=%URL% --start-maximized
    exit
)
if exist "%CHROME%" (
    start /max "" "%CHROME%" --app=%URL% --start-maximized
    exit
)
start %URL%
exit
"@
Set-Content -Path "$portableDir\Iniciar_Servidor.bat" -Value $batContent

$WshShell = New-Object -comObject WScript.Shell
$Shortcut = $WshShell.CreateShortcut("$portableDir\Abrir_Spool.lnk")
$Shortcut.TargetPath = "cmd.exe"
$Shortcut.Arguments = "/c Iniciar_Servidor.bat"
$Shortcut.WorkingDirectory = "$portableDir"
$Shortcut.WindowStyle = 1 
$Shortcut.Save()

Write-Host "=========================================================="
Write-Host "¡Version Portable de Spool Lista!"
Write-Host "Ubicacion: $portableDir"
Write-Host "=========================================================="
