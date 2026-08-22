; SecureLab Agent Installer - NSIS Script (makensis)
; Compilar en Linux: makensis SecureLabAgent.nsi
; Compilar en Windows: makensis SecureLabAgent.nsi
; El token se define en tiempo de compilación: makensis -DAGENT_TOKEN="TUTOKEN" SecureLabAgent.nsi

!include "MUI2.nsh"
!include "FileFunc.nsh"

!define PRODUCT_NAME "SecureLab Agent"
!define PRODUCT_VERSION "2.0.0"
!define COMPANY_NAME "SecureLab"

; Token del agente (definido en tiempo de compilación)
!ifndef AGENT_TOKEN
  !define AGENT_TOKEN "TOKEN_DEL_AGENTE"
!endif

; Configuración general
Name "${PRODUCT_NAME}"
OutFile "SecureLabAgent-Installer.exe"
InstallDir "$PROGRAMFILES\${COMPANY_NAME}\${PRODUCT_NAME}"
RequestExecutionLevel admin
SetCompressor /SOLID lzma
Unicode true
BrandingText "SecureLab Agent Installer"

; Páginas del instalador
!insertmacro MUI_PAGE_LICENSE "LICENSE.txt"
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

; Licencia
LicenseData "LICENSE.txt"

; Variables
; El token ya está definido como AGENT_TOKEN en tiempo de compilación

; Archivos a incluir
Section "SecureLab Agent" SEC01
  SectionIn RO
  
  ; Crear directorio de instalación
  SetOutPath $INSTDIR
  
  ; Copiar archivos
  File "securelab-agent.exe"
  
  ; Crear directorio de logs
  CreateDirectory "$INSTDIR\logs"
  
  ; Crear config.json directamente con el token real
  FileOpen $0 "$INSTDIR\config.json" w
  FileWrite $0 '{$\n'
  FileWrite $0 '  "api_base": "https://leysecurelab.sytes.net/api/agents",$\n'
  FileWrite $0 '  "ws_url": "wss://leysecurelab.sytes.net/ws/",$\n'
  FileWrite $0 '  "token": "${AGENT_TOKEN}",$\n'
  FileWrite $0 '  "heartbeat_interval": 5,$\n'
  FileWrite $0 '  "agent_version": "2.0.0",$\n'
  FileWrite $0 '  "log_level": "debug",$\n'
  FileWrite $0 '  "log_file": "$INSTDIR\\logs\\agent.log",$\n'
  FileWrite $0 '  "audit_db_path": "$INSTDIR\\audit.db",$\n'
  FileWrite $0 '  "knowledge_db_path": "$INSTDIR\\knowledge.db",$\n'
  FileWrite $0 '  "state_file": "$INSTDIR\\.agent-state.json"$\n'
  FileWrite $0 '}$\n'
  FileClose $0
  
  ; Detener y eliminar servicio si existe
  DetailPrint "Verificando servicio existente..."
  nsExec::ExecToLog 'sc query "SecureLabAgent"'
  Pop $0
  ${If} $0 == "0"
    DetailPrint "Deteniendo servicio existente..."
    nsExec::ExecToLog 'sc stop "SecureLabAgent"'
    Sleep 3000
    DetailPrint "Eliminando servicio existente..."
    nsExec::ExecToLog 'sc delete "SecureLabAgent"'
    Sleep 1000
  ${EndIf}
  
  ; Crear servicio Windows
  DetailPrint "Creando servicio Windows..."
  nsExec::ExecToLog 'sc create "SecureLabAgent" binPath= "$INSTDIR\securelab-agent.exe --config $INSTDIR\config.json" start= auto DisplayName= "${PRODUCT_NAME}"'
  nsExec::ExecToLog 'sc description "SecureLabAgent" "SecureLab Monitoring Agent - Reports system status, security, and compliance data"'
  
  ; Configurar recuperación del servicio para reiniciar automáticamente
  DetailPrint "Configurando recuperación automática..."
  nsExec::ExecToLog 'sc failure "SecureLabAgent" reset= 86400 actions= restart/60000/restart/60000/restart/60000'
  
  ; Iniciar servicio con timeout corto usando start command
  DetailPrint "Iniciando servicio..."
  nsExec::Exec 'cmd /c start /b sc start SecureLabAgent'
  Sleep 2000
  
  ; Crear desinstalador
  WriteUninstaller "$INSTDIR\uninstall.exe"
  
  ; Crear registro para Programs and Features
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "DisplayName" "${PRODUCT_NAME}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "DisplayVersion" "${PRODUCT_VERSION}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "Publisher" "${COMPANY_NAME}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "UninstallString" "$INSTDIR\uninstall.exe"
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "NoModify" 1
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}" "NoRepair" 1
  
  ; Mensaje de éxito
  MessageBox MB_OK "✓ ${PRODUCT_NAME} instalado correctamente$\r$\nEl servicio se ha iniciado en segundo plano.$\r$\nEl servicio se reiniciará automáticamente si falla.$\r$\nEl servicio iniciará automáticamente al reiniciar el equipo.$\r$\n$\r$\nEl token ya está configurado en config.json."
SectionEnd

Section "Uninstall"
  ; Detener servicio
  nsExec::ExecToLog 'sc stop "SecureLabAgent"'
  Sleep 2000
  nsExec::ExecToLog 'sc delete "SecureLabAgent"'
  
  ; Eliminar archivos
  Delete "$INSTDIR\securelab-agent.exe"
  Delete "$INSTDIR\config.json"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR\logs"
  RMDir "$INSTDIR"
  
  ; Eliminar registro
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}"
  
  MessageBox MB_OK "✓ ${PRODUCT_NAME} desinstalado correctamente."
SectionEnd