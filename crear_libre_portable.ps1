# --- CONFIGURACION DE RUTAS ---
$portableDir = "C:\xampp\htdocs\AS400_Portable_Libre"
if (!(Test-Path $portableDir)) { New-Item -ItemType Directory -Path $portableDir }

# Solo PHP y redist son necesarios. Python se elimina por no ser ocupado.
foreach ($d in @("php74", "php82", "exports", "redist")) {
    $target = Join-Path $portableDir $d
    if (!(Test-Path $target)) { New-Item -ItemType Directory -Path $target | Out-Null }
}

$cacheDir = "C:\xampp\htdocs\AS400\cache"
if (!(Test-Path $cacheDir)) { New-Item -ItemType Directory -Path $cacheDir | Out-Null }

Write-Host "Verificando Motores y Componentes Críticos..."
$php74Z = "$cacheDir\php74.zip"
$php82Z = "$cacheDir\php82.zip"
$vcX86 = "$cacheDir\vc_redist.x86.exe"
$vcX64 = "$cacheDir\vc_redist.x64.exe"

if (!(Test-Path $php74Z)) { Invoke-WebRequest "https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x86.zip" -OutFile $php74Z -UseBasicParsing }
if (!(Test-Path $php82Z)) { Invoke-WebRequest "https://windows.php.net/downloads/releases/archives/php-8.2.12-nts-Win32-vs16-x64.zip" -OutFile $php82Z -UseBasicParsing }
if (!(Test-Path $vcX86)) { Invoke-WebRequest "https://aka.ms/vs/17/release/vc_redist.x86.exe" -OutFile $vcX86 -UseBasicParsing }
if (!(Test-Path $vcX64)) { Invoke-WebRequest "https://aka.ms/vs/17/release/vc_redist.x64.exe" -OutFile $vcX64 -UseBasicParsing }

Copy-Item $vcX86 "$portableDir\redist\" -Force
Copy-Item $vcX64 "$portableDir\redist\" -Force

Write-Host "Sincronizando Archivos (Modo Optimizado)..."
$excludeList = @("*.log", "*.txt", "trace.log", "debug_raw.txt", "matches.txt", "matches_portable.txt")

# Copia limpia de la app
Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\app\*" "$portableDir\" -Exclude $excludeList
Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\src" "$portableDir\"
Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\vendor" "$portableDir\"
if (Test-Path "C:\xampp\htdocs\AS400\vendor74") { Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\vendor74" "$portableDir\" }

# Archivos base
Copy-Item "C:\xampp\htdocs\AS400\favicon.svg" "$portableDir\"
Copy-Item "C:\xampp\htdocs\AS400\version.json" "$portableDir\"
Copy-Item "C:\xampp\htdocs\AS400\MANUAL_WEB.html" "$portableDir\"
if (Test-Path "C:\xampp\htdocs\AS400\manual_imgs") { Copy-Item -Recurse -Force "C:\xampp\htdocs\AS400\manual_imgs" "$portableDir\" }

# --- OPTIMIZACION DE MOTORES (SLIMMING) ---
Write-Host "Optimizando peso de motores PHP..."
foreach ($engine in @("php74", "php82")) {
    $ePath = Join-Path $portableDir $engine
    if (Test-Path $ePath) {
        # Eliminar carpetas de desarrollo y archivos extra
        Get-ChildItem -Path $ePath -Include "*.txt", "*.md", "php.ini-production", "php.ini-development", "*.bat", "deplister.exe", "README*" -Recurse | Remove-Item -Force -ErrorAction SilentlyContinue
        foreach ($sub in @("dev", "extras", "lib", "include", "phpdbg")) {
            $subPath = Join-Path $ePath $sub
            if (Test-Path $subPath) { Remove-Item -Recurse -Force $subPath }
        }
        # Eliminar binarios no usados (solo necesitamos php.exe y dlls core)
        Get-ChildItem -Path $ePath -Include "php-cgi.exe", "phpdbg.exe", "php-win.exe" | Remove-Item -Force -ErrorAction SilentlyContinue
    }
}

# --- LIMPIEZA PROFUNDA DE VENDOR ---
Write-Host "Limpieza profunda de dependencias (Vendor)..."
foreach ($vDir in @("vendor", "vendor74")) {
    $path = Join-Path $portableDir $vDir
    if (Test-Path $path) {
        # Eliminar carpetas basura de librerías
        Get-ChildItem -Path $path -Directory -Recurse -Include "tests", "test", "docs", "documentation", "examples", "samples", ".git", ".github", "demo" | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
        # Eliminar archivos de metadata
        Get-ChildItem -Path $path -File -Recurse -Include "*.md", "LICENSE*", "composer.json", "composer.lock", "phpunit.xml*", ".gitignore", ".editorconfig" | Remove-Item -Force -ErrorAction SilentlyContinue
    }
}

# Limpiar carpeta de exportaciones antigua
Remove-Item -Path "$portableDir\exports\*" -Include *.* -Force -ErrorAction SilentlyContinue

Write-Host "Generando Iniciar_Servidor.bat (CENTRADITO)..."
$batContent = @'
@echo off
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
cd /d "%ROOT%"
title Spool Explorer [ING. GLR]

:: --- CONFIG VENTANA (MEDIDA Y CENTRADO) ---
mode con: cols=75 lines=28
powershell -ExecutionPolicy Bypass -Command "$h=(Get-Process -Id $pid).MainWindowHandle; $s=[System.Windows.Forms.Screen]::PrimaryScreen.Bounds; $w=630; $h2=520; [void](Add-Type -MemberDefinition '[DllImport(\"user32.dll\")]public static extern bool MoveWindow(IntPtr h,int x,int y,int w,int h,bool r);' -Name 'W' -Namespace 'N' -PassThru)::MoveWindow($h,($s.Width-$w)/2,($s.Height-$h2)/2,$w,$h2,$True)"

:: --- ESTETICA HACKER ---
color 0a
cls

echo.
echo      _____  _____  _____  _____  _      
echo     /  ___/^|^|  __ \^|^|  _  ^|^|^|  _  ^|^|^| ^|     
echo     \ `--. ^|^| ^|__) ^|^| ^| ^| ^|^|^| ^| ^| ^|^|^| ^|     
echo      `--. \^|^|  ___/^|^| ^| ^| ^|^|^| ^| ^| ^|^|^| ^|     
echo     /\__/ /^|^| ^|    \ \_/ /\ \_/ /^|^| ^|____ 
echo     \____/ ^|^|_^|     \___/  \___/ \_____/ 
echo.
echo     --- [ AS/400 SPOOL EXPLORER v1.7.2 ] ---
echo     --- [ Ing. Gabriel Lopez Reyes     ] ---
echo.
echo     =============================================

set "PHP_ENGINE=php82"
ver | find "6.1" >nul && set "PHP_ENGINE=php74"
ver | find "6.0" >nul && set "PHP_ENGINE=php74"

echo     [!] SISTEMA: %PHP_ENGINE%_ACTIVE
timeout /t 1 /nobreak > nul

echo     [#] LIMPIANDO_SESIONES_Y_LOGS...
taskkill /f /im php-win.exe /t >nul 2>&1
taskkill /f /im php.exe /t >nul 2>&1
if exist server_logs.txt del server_logs.txt
if exist exports\*.xlsx del exports\*.xlsx
if exist exports\*.pdf del exports\*.pdf

echo     [$] INYECTANDO_MOTOR_SPOOL...
"%PHP_ENGINE%\php.exe" -v >nul 2>&1
if !errorlevel! neq 0 (
    color 0c
    cls
    echo.
    echo     [!] ERROR: COMPONENTES CRITICOS FALTANTES
    echo     -------------------------------------------
    echo     DEBE EJECUTAR: redist\vc_redist.!PHP_ENGINE:~3!.exe
    pause
    exit
)

start /b "" "%PHP_ENGINE%\php.exe" -c "%PHP_ENGINE%\php.ini" -S 127.0.0.1:8181 > server_logs.txt 2>&1
timeout /t 2 /nobreak > nul

echo     [+] ABRIENDO_INTERFACE_GLR...
set "URL=http://127.0.0.1:8181"
set "APP_OPENED=0"

set "EDGE_CMD="
if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" set "EDGE_CMD="%ProgramFiles%\Microsoft\Edge\Application\msedge.exe""
if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" set "EDGE_CMD="%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe""

if defined EDGE_CMD ( 
    start "" !EDGE_CMD! --app=%URL% --start-maximized
    set "APP_OPENED=1" 
)

if "!APP_OPENED!"=="0" (
    set "CHROME_CMD="
    if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROME_CMD="%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe""
    if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROME_CMD="%ProgramFiles%\Google\Chrome\Application\chrome.exe""
    if defined CHROME_CMD ( 
        start "" !CHROME_CMD! --app=%URL% --start-maximized
        set "APP_OPENED=1" 
    )
)

if "!APP_OPENED!"=="0" ( start %URL% )

echo.
echo     =============================================
echo     ESTADO: SISTEMA_OPERATIVO_PORTABLE
echo     UPLINK: ENLACE_ESTABLECIDO_PUERTO_8181
echo     =============================================
echo     Presione cualquier tecla para cerrar el servidor.
pause > nul
taskkill /f /im php.exe /t >nul 2>&1
exit
'@

Set-Content -Path "$portableDir\Iniciar_Servidor.bat" -Value $batContent
Write-Host "¡Versión Centrada y de Tamaño Compacto creada!"
