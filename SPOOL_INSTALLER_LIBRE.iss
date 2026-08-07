; Instalador Spool Profesional (Autogenerado)
[Setup]
AppName=Spool Enterprise Explorer
AppVersion=1.7.0
DefaultDirName={pf}\SpoolExplorer
DefaultGroupName=Spool Explorer
OutputBaseFilename=Instalador_Spool_Libre
Compression=lzma
SolidCompression=yes
SetupIconFile=
UninstallDisplayIcon={app}\Iniciar_Servidor.bat

[Files]
; Copiar toda la carpeta portable creada
Source: "C:\xampp\htdocs\AS400_Portable_Libre\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\Spool Explorer"; Filename: "{app}\Iniciar_Servidor.bat"
Name: "{commondesktop}\Spool Explorer"; Filename: "{app}\Iniciar_Servidor.bat"

[Run]
Filename: "{app}\Iniciar_Servidor.bat"; Description: "{cm:LaunchProgram,Spool Explorer}"; Flags: nowait postinstall skipifsilent
