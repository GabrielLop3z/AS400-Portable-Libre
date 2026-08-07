@echo off
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
cd /d "%ROOT%"
title Spool Explorer [SIMULACION WIN7 - APP MODE]

:: --- CONFIG VENTANA ---
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
echo     --- [ SIMULADOR DE COMPATIBILIDAD ] ---
echo     --- [ MODO APP + MOTOR LEGACY 7.4 ] ---
echo.
echo     =============================================

:: --- FORZAMOS MOTOR LEGACY ---
set "PHP_ENGINE=php74"

echo     [!] MODO_APP: FORZANDO_INTERFACE_LIMPIA
echo     [!] MOTOR: %PHP_ENGINE%_LEGACY_ACTIVE
timeout /t 2 /nobreak > nul

echo     [#] LIMPIANDO_PROCESOS...
taskkill /f /im php-win.exe /t >nul 2>&1
taskkill /f /im php.exe /t >nul 2>&1

echo     [$] INYECTANDO_LOGICA_ESTABLE...
if exist server_logs.txt del server_logs.txt

"%PHP_ENGINE%\php.exe" -v >nul 2>&1
if !errorlevel! neq 0 (
    color 0e
    echo.
    echo     [!] OMITIENDO ERROR: MOTOR PHP 7.4 NO RESPONDE
    echo     [#] INSTALANDO LIBRERIAS C++ AUTOMATICAMENTE... POR FAVOR ESPERE...
    
    start /wait "" "redist\vc_redist.x86.exe" /passive /norestart
    if "%PROCESSOR_ARCHITECTURE%"=="AMD64" start /wait "" "redist\vc_redist.x64.exe" /passive /norestart
    if "%PROCESSOR_ARCHITEW6432%"=="AMD64" start /wait "" "redist\vc_redist.x64.exe" /passive /norestart
    
    echo     [+] INSTALACION COMPLETADA. REINICIANDO MOTOR...
    "%PHP_ENGINE%\php.exe" -v >nul 2>&1
    if !errorlevel! neq 0 (
        color 0c
        echo.
        echo     [!] ERROR CRITICO: MOTOR PHP 7.4 NO RESPONDE AUN DESPUES DE INSTALAR.
        pause
        exit
    )
    color 0a
)

start /b "" "%PHP_ENGINE%\php.exe" -c "%PHP_ENGINE%\php.ini" -S 127.0.0.1:8181 > server_logs.txt 2>&1
timeout /t 4 /nobreak > nul

:: --- LANZAMIENTO MODO APP ---
echo     [+] ABRIENDO_APP_CENTRAL...
set "URL=http://127.0.0.1:8181"
set "APP_OPENED=0"

set "EDGE_EXE="
if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" set "EDGE_EXE=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" set "EDGE_EXE=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if defined EDGE_EXE ( start "" "%EDGE_EXE%" --app=%URL% --start-maximized & set "APP_OPENED=1" )

if "!APP_OPENED!"=="0" (
    set "CHROME_EXE="
    if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROME_EXE=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
    if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROME_EXE=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
    if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" set "CHROME_EXE=%LocalAppData%\Google\Chrome\Application\chrome.exe"
    if defined CHROME_EXE ( start "" "%CHROME_EXE%" --app=%URL% --start-maximized & set "APP_OPENED=1" )
)

if "!APP_OPENED!"=="0" (
    set "FIREFOX_EXE="
    if exist "%ProgramFiles%\Mozilla Firefox\firefox.exe" set "FIREFOX_EXE=%ProgramFiles%\Mozilla Firefox\firefox.exe"
    if exist "%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe" set "FIREFOX_EXE=%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe"
    if defined FIREFOX_EXE ( start "" "%FIREFOX_EXE%" %URL% & set "APP_OPENED=1" )
)

if "!APP_OPENED!"=="0" (
    set "BRAVE_EXE="
    if exist "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" set "BRAVE_EXE=%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe"
    if exist "%ProgramFiles(x86)%\BraveSoftware\Brave-Browser\Application\brave.exe" set "BRAVE_EXE=%ProgramFiles(x86)%\BraveSoftware\Brave-Browser\Application\brave.exe"
    if exist "%LocalAppData%\BraveSoftware\Brave-Browser\Application\brave.exe" set "BRAVE_EXE=%LocalAppData%\BraveSoftware\Brave-Browser\Application\brave.exe"
    if defined BRAVE_EXE ( start "" "%BRAVE_EXE%" --app=%URL% --start-maximized & set "APP_OPENED=1" )
)

if "!APP_OPENED!"=="0" ( 
    color 0c
    cls
    echo.
    echo     [!] ADVERTENCIA CRITICA DE COMPATIBILIDAD
    echo     =============================================================
    echo     NO SE DETECTO NINGUN NAVEGADOR MODERNO EN ESTE EQUIPO 
    echo     ^(G. CHROME, MS EDGE, FIREFOX O BRAVE^).
    echo.
    echo     SPOOL EXPLORER NO ES COMPATIBLE CON INTERNET EXPLORER.
    echo     LA INTERFAZ GRAFICA SE VERA DISTORSIONADA O NO FUNCIONARA.
    echo.
    echo     POR FAVOR, INSTALE GOOGLE CHROME PARA UNA MEJOR EXPERIENCIA
    echo     ANTES DE INTENTAR USAR EL SISTEMA.
    echo     =============================================================
    echo.
    echo     Presione cualquier tecla si desea continuar de todos modos...
    pause > nul
    start %URL% 
    color 0a
    cls
)

echo.
echo     =============================================
echo     ESTADO: SIMULACION_LEGACY_EXITOSA
echo     UPLINK: ACTIVO_EN_MODO_APLICACION
echo     =============================================
echo     Presiona cualquier tecla para terminar...
pause > nul
taskkill /f /im php.exe /t >nul 2>&1
exit
