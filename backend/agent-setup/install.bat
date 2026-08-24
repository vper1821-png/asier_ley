@echo off
echo ============================================
echo   SecureLab Agent Installer v2.0
echo ============================================
echo.
echo Deteniendo y eliminando servicio anterior (si existe)...
sc stop SecureLabAgent 2>nul
timeout /t 3 /nobreak >nul
sc delete SecureLabAgent 2>nul
echo.
echo Instalando agente en C:\Program Files (x86)\SecureLab\SecureLab Agent...
if not exist "C:\Program Files (x86)\SecureLab\SecureLab Agent" mkdir "C:\Program Files (x86)\SecureLab\SecureLab Agent"
copy /Y "%~dp0securelab-agent.exe" "C:\Program Files (x86)\SecureLab\SecureLab Agent\securelab-agent.exe"
if exist "%~dp0config.json" (
    copy /Y "%~dp0config.json" "C:\Program Files (x86)\SecureLab\SecureLab Agent\config.json"
) else (
    if not exist "C:\Program Files (x86)\SecureLab\SecureLab Agent\config.json" (
        copy /Y "%~dp0config-template.json" "C:\Program Files (x86)\SecureLab\SecureLab Agent\config.json"
    )
)
echo.
echo Instalando como servicio Windows...
"C:\Program Files (x86)\SecureLab\SecureLab Agent\securelab-agent.exe" install
echo.
echo Instalacion completada.
echo.
echo El servicio se iniciara automaticamente. Si no, inicio manual:
echo   sc start SecureLabAgent
echo.
