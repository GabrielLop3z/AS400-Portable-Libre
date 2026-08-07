@echo on
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
cd /d "%ROOT%"
title Lanzador Spool DEBUG

echo [DEBUG] Iniciando...
pause

set "PHP_DIR=php82"
echo [DEBUG] Usando %PHP_DIR% por defecto para Windows 10
pause

echo [DEBUG] Limpiando procesos...
taskkill /f /im php-win.exe /t >nul 2>&1
taskkill /f /im php.exe /t >nul 2>&1
pause

if exist server_logs.txt del server_logs.txt

echo [DEBUG] Probando PHP...
"%PHP_DIR%\php.exe" -v
if %errorlevel% neq 0 (
    echo [ERROR] No se pudo ejecutar PHP.exe, intentando instalar VC_Redist...
    start /wait "" "redist\vc_redist.x86.exe" /passive /norestart
    if "%PROCESSOR_ARCHITECTURE%"=="AMD64" start /wait "" "redist\vc_redist.x64.exe" /passive /norestart
    if "%PROCESSOR_ARCHITEW6432%"=="AMD64" start /wait "" "redist\vc_redist.x64.exe" /passive /norestart
    "%PHP_DIR%\php.exe" -v >nul 2>&1
    if !errorlevel! neq 0 (
        echo [ERROR CRITICO] Sigue sin ejecutarse PHP.exe.
        pause
        exit
    )
)
pause

echo [DEBUG] Arrancando servidor...
start "" "%PHP_DIR%\php.exe" -S 127.0.0.1:8181
pause

echo [DEBUG] Navegador...
start http://127.0.0.1:8181
pause

echo [DEBUG] Fin.
pause
exit
