; ==============================================================================
; SecureLab Agent Installer - NSIS Script (makensis)
; Compilar en Linux / Windows:
;   makensis -DAGENT_TOKEN="<token>" SecureLabAgent.nsi
; ==============================================================================

!include "MUI2.nsh"
!include "FileFunc.nsh"
!include "LogicLib.nsh"

!define PRODUCT_NAME "SecureLab Agent"
!define PRODUCT_VERSION "2.0.0"
!define COMPANY_NAME "SecureLab"

; Parámetros configurables en tiempo de compilación
!ifndef AGENT_TOKEN
  !define AGENT_TOKEN ""
!endif

!ifndef API_BASE
  !define API_BASE "https://leysecurelab.sytes.net/api/agents"
!endif

!ifndef WS_URL
  !define WS_URL "wss://leysecurelab.sytes.net/ws/"
!endif

!ifndef AGENT_EXE
  !define AGENT_EXE "securelab-agent.exe"
!endif

!ifndef OUTFILE
  !define OUTFILE "SecureLabAgent-Installer.exe"
!endif

; Configuración general
Name "${PRODUCT_NAME}"
OutFile "${OUTFILE}"
InstallDir "$PROGRAMFILES\${COMPANY_NAME}\${PRODUCT_NAME}"
InstallDirRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "InstallLocation"
RequestExecutionLevel admin
SetCompressor /SOLID zlib
Unicode true
BrandingText "SecureLab Agent Installer v${PRODUCT_VERSION}"

; Icono por defecto de NSIS (evita bug de parsing en makensis Linux)

; Páginas del instalador
!insertmacro MUI_PAGE_LICENSE "LICENSE.txt"
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

; Idiomas
!insertmacro MUI_LANGUAGE "Spanish"
!insertmacro MUI_LANGUAGE "English"

; ------------------------------------------------------------------------------
; Instalación
; ------------------------------------------------------------------------------
Section "SecureLab Agent" SEC01
  SectionIn RO
  
  ; Crear directorio de instalación
  SetOutPath "$INSTDIR"
  
  ; Copiar binario del agente
  File "/oname=securelab-agent.exe" "${AGENT_EXE}"
  
  ; Crear directorio de datos y logs en ProgramData (escribible por el servicio SYSTEM)
  CreateDirectory "$ALLUSERSPROFILE\SecureLab Agent\logs"
  CreateDirectory "$ALLUSERSPROFILE\SecureLab Agent\data"
  nsExec::ExecToLog 'icacls "$ALLUSERSPROFILE\SecureLab Agent" /grant "SYSTEM:(OI)(CI)F" /grant "Administrators:(OI)(CI)F" /grant "Users:(OI)(CI)M" /T'
  
  ; Generar config.json con las rutas correctas y el token
  FileOpen $0 "$INSTDIR\config.json" w
  FileWrite $0 '{$\r$\n'
  FileWrite $0 '  "api_base": "${API_BASE}",$\r$\n'
  FileWrite $0 '  "ws_url": "${WS_URL}",$\r$\n'
  FileWrite $0 '  "token": "${AGENT_TOKEN}",$\r$\n'
  FileWrite $0 '  "heartbeat_interval": 5,$\r$\n'
  FileWrite $0 '  "agent_version": "${PRODUCT_VERSION}",$\r$\n'
  FileWrite $0 '  "log_level": "debug",$\r$\n'
  FileWrite $0 '  "log_file": "$ALLUSERSPROFILE\\SecureLab Agent\\logs\\agent.log",$\r$\n'
  FileWrite $0 '  "audit_db_path": "$ALLUSERSPROFILE\\SecureLab Agent\\data\\audit.db",$\r$\n'
  FileWrite $0 '  "knowledge_db_path": "$ALLUSERSPROFILE\\SecureLab Agent\\data\\knowledge.db",$\r$\n'
  FileWrite $0 '  "state_file": "$ALLUSERSPROFILE\\SecureLab Agent\\data\\.agent-state.json",$\r$\n'
  FileWrite $0 '  "persistence_mode": "aggressive",$\r$\n'
  FileWrite $0 '  "hardening_enabled": true$\r$\n'
  FileWrite $0 '}$\r$\n'
  FileClose $0
  
  ; Detener y eliminar servicio previo si ya existía
  DetailPrint "Verificando servicio existente..."
  nsExec::ExecToLog 'sc query "SecureLabAgent"'
  Pop $0
  ${If} $0 == "0"
    DetailPrint "Deteniendo servicio anterior..."
    nsExec::ExecToLog 'sc stop "SecureLabAgent"'
    Sleep 2000
    DetailPrint "Eliminando servicio anterior..."
    nsExec::ExecToLog 'sc delete "SecureLabAgent"'
    Sleep 1000
  ${EndIf}
  
  ; Registrar servicio en el Administrador de Servicios de Windows (SCM)
  DetailPrint "Registrando servicio SecureLabAgent..."
  nsExec::ExecToLog 'sc create "SecureLabAgent" binPath= "\"$INSTDIR\securelab-agent.exe\"" start= auto DisplayName= "${PRODUCT_NAME}"'
  nsExec::ExecToLog 'sc description "SecureLabAgent" "SecureLab Security Agent - Endpoint protection, host monitoring and compliance."'
  
  ; Configurar recuperación automática en caso de fallo
  DetailPrint "Configurando auto-recuperación..."
  nsExec::ExecToLog 'sc failure "SecureLabAgent" reset= 86400 actions= restart/5000/restart/10000/restart/30000'
  
  ; Conceder permisos de escritura en logs y bases de datos para todos los usuarios
  DetailPrint "Configurando permisos de acceso..."
  nsExec::ExecToLog 'icacls "$INSTDIR" /grant *S-1-5-32-545:(OI)(CI)M /T /C /Q'
  nsExec::ExecToLog 'icacls "$INSTDIR\logs" /grant *S-1-5-32-545:(OI)(CI)F /T /C /Q'
  
  ; Registrar auto-arranque en sesion de usuario para el overlay de bloqueo
  DetailPrint "Registrando inicio automatico en sesion de usuario..."
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "SecureLabAgent" "$\"$INSTDIR\securelab-agent.exe$\" --check-lockdown"
  
  ; Iniciar el servicio Windows
  DetailPrint "Iniciando servicio SecureLabAgent..."
  nsExec::ExecToLog 'net start "SecureLabAgent"'
  Sleep 1000
  nsExec::ExecToLog 'sc start "SecureLabAgent"'
  Sleep 1500
  
  ; Crear desinstalador
  WriteUninstaller "$INSTDIR\uninstall.exe"
  
  ; Registro para Programas y Características de Windows
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "DisplayName" "${PRODUCT_NAME}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "DisplayVersion" "${PRODUCT_VERSION}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "Publisher" "${COMPANY_NAME}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "UninstallString" "$\"$INSTDIR\uninstall.exe$\""
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "InstallLocation" "$INSTDIR"
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "NoModify" 1
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "NoRepair" 1
  
  MessageBox MB_OK|MB_ICONINFORMATION "✓ ${PRODUCT_NAME} instalado y configurado correctamente.$\r$\n$\r$\nEl servicio se encuentra activo en segundo plano y se iniciará automáticamente con el sistema."
SectionEnd

; ------------------------------------------------------------------------------
; Desinstalación
; ------------------------------------------------------------------------------
Section "Uninstall"
  ; Detener y eliminar servicio
  DetailPrint "Deteniendo servicio..."
  nsExec::ExecToLog 'sc stop "SecureLabAgent"'
  Sleep 2000
  DetailPrint "Eliminando servicio..."
  nsExec::ExecToLog 'sc delete "SecureLabAgent"'
  Sleep 1000
  
  ; Eliminar registro de auto-arranque
  DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "SecureLabAgent"
  
  ; Eliminar archivos de la aplicación
  Delete "$INSTDIR\securelab-agent.exe"
  Delete "$INSTDIR\config.json"
  Delete "$INSTDIR\uninstall.exe"
  Delete "$INSTDIR\audit.db"
  Delete "$INSTDIR\audit.db-shm"
  Delete "$INSTDIR\audit.db-wal"
  Delete "$INSTDIR\knowledge.db"
  Delete "$INSTDIR\knowledge.db-shm"
  Delete "$INSTDIR\knowledge.db-wal"
  Delete "$INSTDIR\pending.db"
  Delete "$INSTDIR\pending.db-shm"
  Delete "$INSTDIR\pending.db-wal"
  Delete "$INSTDIR\.agent-state.json"
  Delete "$INSTDIR\.securelab-*"
  Delete "$INSTDIR\logs\agent.log"
  Delete "$INSTDIR\logs\*"
  RMDir "$INSTDIR\logs"
  RMDir "$INSTDIR"
  
  ; Eliminar entrada del registro
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}"
  
  MessageBox MB_OK|MB_ICONINFORMATION "✓ ${PRODUCT_NAME} ha sido desinstalado correctamente del equipo."
SectionEnd
