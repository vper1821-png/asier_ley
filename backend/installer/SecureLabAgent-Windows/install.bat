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
echo Instalando agente en C:\Program Files\SecureLab Agent...
if not exist "C:\Program Files\SecureLab Agent" mkdir "C:\Program Files\SecureLab Agent"
copy /Y "%~dp0securelab-agent.exe" "C:\Program Files\SecureLab Agent\securelab-agent.exe"
if exist "%~dp0config.json" (
    copy /Y "%~dp0config.json" "C:\Program Files\SecureLab Agent\config.json"
) else (
    if not exist "C:\Program Files\SecureLab Agent\config.json" (
        echo {"api_base":"http://localhost:3838/api/agents","heartbeat_interval":5,"agent_version":"2.0.0","hardening_enabled":true,"persistence_mode":"aggressive","log_level":"info"} > "C:\Program Files\SecureLab Agent\config.json"
    )
)
echo.
echo Instalando como servicio Windows...
"C:\Program Files\SecureLab Agent\securelab-agent.exe" install
echo.
echo Instalacion completada.
echo.
echo El servicio se iniciara automaticamente. Si no, inicio manual:
echo   sc start SecureLabAgent
echo.
pause
