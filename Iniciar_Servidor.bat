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
echo     --- [ AS/400 SPOOL EXPLORER v1.7.5 ] ---
echo.
echo     =============================================

:: --- VALIDACION DE ACCESO DIRECTO ---
set "DESKTOP_LNK=%USERPROFILE%\Desktop\Spool.lnk"
if not exist "!DESKTOP_LNK!" (
    echo     [#] CONFIGURANDO_ACCESO_DIRECTO_AUTO...
    powershell -ExecutionPolicy Bypass -File "%ROOT%setup_shortcut.ps1" > nul 2>&1
)

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
    color 0e
    echo.
    echo     [!] OMITIENDO ERROR: COMPONENTES CRITICOS FALTANTES
    echo     [#] INSTALANDO LIBRERIAS C++ AUTOMATICAMENTE... POR FAVOR ESPERE...
    
    start /wait "" "redist\vc_redist.x86.exe" /passive /norestart
    if "%PROCESSOR_ARCHITECTURE%"=="AMD64" start /wait "" "redist\vc_redist.x64.exe" /passive /norestart
    if "%PROCESSOR_ARCHITEW6432%"=="AMD64" start /wait "" "redist\vc_redist.x64.exe" /passive /norestart
    
    echo     [+] INSTALACION COMPLETADA. REINICIANDO MOTOR...
    "%PHP_ENGINE%\php.exe" -v >nul 2>&1
    if !errorlevel! neq 0 (
        color 0c
        echo.
        echo     [!] ERROR CRITICO: NO SE PUDO INICIAR PHP AUN DESPUES DE INSTALAR.
        echo     EJECUTE MANUALMENTE LOS INSTALADORES EN LA CARPETA 'redist'.
        pause
        exit
    )
    color 0a
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
    if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" set "CHROME_CMD="%LocalAppData%\Google\Chrome\Application\chrome.exe""
    if defined CHROME_CMD ( 
        start "" !CHROME_CMD! --app=%URL% --start-maximized
        set "APP_OPENED=1" 
    )
)

if "!APP_OPENED!"=="0" (
    set "FIREFOX_CMD="
    if exist "%ProgramFiles%\Mozilla Firefox\firefox.exe" set "FIREFOX_CMD="%ProgramFiles%\Mozilla Firefox\firefox.exe""
    if exist "%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe" set "FIREFOX_CMD="%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe""
    if defined FIREFOX_CMD ( 
        start "" !FIREFOX_CMD! %URL%
        set "APP_OPENED=1" 
    )
)

if "!APP_OPENED!"=="0" (
    set "BRAVE_CMD="
    if exist "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" set "BRAVE_CMD="%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe""
    if exist "%ProgramFiles(x86)%\BraveSoftware\Brave-Browser\Application\brave.exe" set "BRAVE_CMD="%ProgramFiles(x86)%\BraveSoftware\Brave-Browser\Application\brave.exe""
    if exist "%LocalAppData%\BraveSoftware\Brave-Browser\Application\brave.exe" set "BRAVE_CMD="%LocalAppData%\BraveSoftware\Brave-Browser\Application\brave.exe""
    if defined BRAVE_CMD ( 
        start "" !BRAVE_CMD! --app=%URL% --start-maximized
        set "APP_OPENED=1" 
    )
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
echo     ESTADO: SISTEMA_OPERATIVO_PORTABLE
echo     UPLINK: ENLACE_ESTABLECIDO_PUERTO_8181
echo     =============================================
echo     Presione cualquier tecla para cerrar el servidor.
pause > nul
taskkill /f /im php.exe /t >nul 2>&1
exit
