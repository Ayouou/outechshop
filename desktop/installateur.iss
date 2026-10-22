; Installateur Windows d'OUTECH (Inno Setup 6).
; Construit par GitHub Actions : voir .github/workflows/build-exe.yml.
; Attend le dossier `build\app` (OUTECH.exe + php\ + www\).

#ifndef Version
  #define Version "1.0.0"
#endif

[Setup]
AppId={{6C3E8B2A-4F0D-4C55-9E1A-0D7B3A2F8E41}
AppName=OUTECH
AppVersion={#Version}
AppPublisher=OUTECH
DefaultDirName={autopf}\OUTECH
DefaultGroupName=OUTECH
DisableProgramGroupPage=yes
OutputDir=..\build\installateur
OutputBaseFilename=OUTECH-Setup-{#Version}
Compression=lzma2/max
SolidCompression=yes
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequiredOverridesAllowed=dialog
UninstallDisplayIcon={app}\OUTECH.exe
WizardStyle=modern

[Languages]
Name: "fr"; MessagesFile: "compiler:Languages\French.isl"

[Tasks]
Name: "bureau"; Description: "Créer une icône sur le Bureau"; GroupDescription: "Raccourcis :"

[Files]
Source: "..\build\app\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\OUTECH"; Filename: "{app}\OUTECH.exe"
Name: "{group}\OUTECH (changer de mode)"; Filename: "{app}\OUTECH.exe"; Parameters: "--choisir-mode"
Name: "{autodesktop}\OUTECH"; Filename: "{app}\OUTECH.exe"; Tasks: bureau

[Run]
Filename: "{app}\OUTECH.exe"; Description: "Lancer OUTECH"; Flags: nowait postinstall skipifsilent

; Les données (%APPDATA%\OUTECH) ne sont jamais supprimées à la désinstallation.
