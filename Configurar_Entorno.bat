@echo off
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
cd /d "%ROOT%"

:: --- CONFIGURACION ---
set "RUNTIME_URL=https://github.com/GabrielLop3z/AS400-Portable-Libre/releases/download/runtime-v1/AS400_Portable_Runtime_x64.zip"
set "RUNTIME_ZIP=%TEMP%\AS400_Portable_Runtime_x64.zip"

:: --- SI EL ENTORNO YA ESTA COMPLETO, SALIR ---
if exist "php82\php.exe" if exist "php74\php.exe" if exist "python311\python.exe" if exist "redist\vc_redist.x64.exe" (
    exit /b 0
)

cls
echo.
echo  ============================================================
echo   CONFIGURACION DEL ENTORNO PORTABLE
echo  ============================================================
echo.
echo  [#] NO SE DETECTARON LOS COMPONENTES PORTABLES (php74,
echo      php82, python311 y redist).
echo  [#] LA PRIMERA EJECUCION DESCARGARA E INSTALARA
echo      AUTOMATICAMENTE PHP + PYTHON + VC REDIST (115 MB).
echo  [#] PUEDE TARDAR UNOS MINUTOS SEGUN SU CONEXION...
echo.

:: --- LIMPIAR RESTOS DE DESCARGAS ANTERIORES ---
if exist "%RUNTIME_ZIP%" del "%RUNTIME_ZIP%" >nul 2>&1
for %%D in (php74 php82 python311 python38 redist) do if exist "%%D" rmdir /s /q "%%D" 2>nul

:: --- DESCARGAR (curl si existe, si no PowerShell/WebClient) ---
echo  [$] DESCARGANDO COMPONENTES...
where curl.exe >nul 2>&1
if !errorlevel! equ 0 (
    curl.exe -sL --retry 3 --connect-timeout 30 -o "%RUNTIME_ZIP%" "%RUNTIME_URL%"
) else (
    powershell -ExecutionPolicy Bypass -Command "(New-Object System.Net.WebClient).DownloadFile('%RUNTIME_URL%','%RUNTIME_ZIP%')"
)

if not exist "%RUNTIME_ZIP%" (
    color 0c
    echo.
    echo  [!] ERROR: NO SE PUDO DESCARGAR LOS COMPONENTES.
    echo  [!] VERIFIQUE SU CONEXION A INTERNET Y VUELVA A EJECUTAR.
    echo  [!] DESCARGA MANUAL: %RUNTIME_URL%
    echo      Descomprimala en esta misma carpeta y vuelva a iniciar.
    echo.
    pause
    exit /b 1
)

:: --- EXTRAER ---
echo  [$] EXTRAYENDO COMPONENTES...
powershell -ExecutionPolicy Bypass -Command "Add-Type -AssemblyName System.IO.Compression.FileSystem; [System.IO.Compression.ZipFile]::ExtractToDirectory('%RUNTIME_ZIP%','%ROOT%')"
if exist "%RUNTIME_ZIP%" del "%RUNTIME_ZIP%" >nul 2>&1

:: --- VERIFICAR ---
if exist "php82\php.exe" if exist "php74\php.exe" if exist "python311\python.exe" if exist "redist\vc_redist.x64.exe" (
    color 0a
    echo.
    echo  [+] ENTORNO PORTABLE INSTALADO CORRECTAMENTE.
    echo.
    exit /b 0
)

color 0c
echo.
echo  [!] ERROR: LA EXTRACCION NO COMPLETO LOS COMPONENTES.
echo  [!] DESCARGA MANUAL: %RUNTIME_URL%
echo      Descomprimala en esta misma carpeta y vuelva a iniciar.
echo.
pause
exit /b 1
