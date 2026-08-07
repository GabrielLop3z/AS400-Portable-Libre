# setup_shortcut.ps1 - CORRECCION DINAMICA PARA PORTABILIDAD
$WshShell = New-Object -comObject WScript.Shell
$DesktopPath = [System.Environment]::GetFolderPath("Desktop")
$ShortcutPath = Join-Path $DesktopPath "Spool.lnk"

# Detectar la carpeta actual de forma dinamica (independiente de la ruta absoluta)
$CurrentDir = $PSScriptRoot
if (!$CurrentDir) { $CurrentDir = Get-Location }

$BatPath = Join-Path $CurrentDir "Iniciar_Servidor.bat"
$IcoPath = Join-Path $CurrentDir "spool.ico"

$Shortcut = $WshShell.CreateShortcut($ShortcutPath)
$Shortcut.TargetPath = "C:\Windows\System32\cmd.exe"
$Shortcut.Arguments = "/c `"$BatPath`""
$Shortcut.WorkingDirectory = $CurrentDir
$Shortcut.WindowStyle = 7 # Minimizada

# Vinculamos el icono spool.ico de la carpeta raiz
if (Test-Path $IcoPath) {
    $Shortcut.IconLocation = "$IcoPath"
} else {
    $Shortcut.IconLocation = "shell32.dll, 23"
}

$Shortcut.Description = "Sistema AS/400 Spool Explorer"
$Shortcut.Save()

Write-Host "[OK] Acceso directo 'Spool' configurado dinamicamente." -ForegroundColor Cyan
