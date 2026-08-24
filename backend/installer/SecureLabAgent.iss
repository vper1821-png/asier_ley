; SecureLab Agent Installer - Inno Setup Script
; Requiere Inno Setup: https://jrsoftware.org/isdl.php

[Setup]
AppName=SecureLab Agent
AppVersion=2.0.0
AppPublisher=SecureLab
DefaultDirName={pf}\SecureLab\SecureLab Agent
DefaultGroupName=SecureLab Agent
OutputBaseFilename=SecureLabAgent-Setup
Compression=lzma
SolidCompression=yes
PrivilegesRequired=admin
WizardStyle=modern
DisableDirPage=no
DisableProgramGroupPage=yes
; Logo del instalador
WizardImageFile=installer-logo.bmp
WizardSmallImageFile=installer-small.bmp

[Languages]
Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"

[Files]
Source: "securelab-agent.exe"; DestDir: "{app}"
Source: "config.json"; DestDir: "{app}"
; Logo para mostrar en la instalación final
Source: "installer-logo.bmp"; DestDir: "{app}"; Flags: ignoreversion
; Script de inicialización
Source: "init-agent.ps1"; DestDir: "{app}"

[Dirs]
Name: "{app}\logs"

[Icons]
Name: "{group}\Uninstall SecureLab Agent"; Filename: "{uninstallexe}"
Name: "{commondesktop}\Configurar Agente SecureLab"; Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -WindowStyle Normal -File ""{app}\init-agent.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\securelab-agent.exe"; Comment: "Ejecuta después de la instalación para configurar el token"

[Run]
; Crear servicio Windows
Filename: "sc.exe"; Parameters: "create ""SecureLabAgent"" binPath= ""{app}\securelab-agent.exe --config {app}\config.json"" start= auto DisplayName= ""SecureLab Agent"""; Flags: runhidden
Filename: "sc.exe"; Parameters: "description ""SecureLabAgent"" ""SecureLab Monitoring Agent"""; Flags: runhidden
; Iniciar servicio
Filename: "sc.exe"; Parameters: "start ""SecureLabAgent"""; Flags: runhidden

[UninstallRun]
; Detener servicio
Filename: "sc.exe"; Parameters: "stop ""SecureLabAgent"""; Flags: runhidden
Filename: "sc.exe"; Parameters: "delete ""SecureLabAgent"""; Flags: runhidden

[UninstallDelete]
Type: filesandordirs; Name: "{app}\logs"

[Code]
var
  AgentToken: String;

function InitializeSetup(): Boolean;
begin
  // Verificar si se ejecuta como administrador
  if not IsAdminLoggedOn then begin
    MsgBox('Este instalador requiere privilegios de administrador. Por favor ejecuta como administrador.', mbError, MB_OK);
    Result := False;
  end else begin
    Result := True;
  end;
  
  // Obtener token del parámetro de línea de comandos
  AgentToken := ExpandConstant('{param:token|}');
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  TokenFile: String;
begin
  if CurStep = ssPostInstall then begin
    // Si se proporcionó un token, guardarlo en un archivo
    if AgentToken <> '' then begin
      TokenFile := ExpandConstant('{app}\token.txt');
      SaveStringToFile(TokenFile, AgentToken, False);
    end;
  end;
end;