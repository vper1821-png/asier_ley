@echo off
echo ============================================
echo   SecureLab Agent Installer v2.0
echo ============================================
echo.
echo Instalando agente en C:\Program Files\SecureLab Agent...
if not exist "C:\Program Files\SecureLab Agent" mkdir "C:\Program Files\SecureLab Agent"
copy /Y "%~dp0securelab-agent.exe" "C:\Program Files\SecureLab Agent\securelab-agent.exe"
if not exist "C:\Program Files\SecureLab Agent\config.json" (
    echo {"api_base":"http://localhost:3838/api/agents","heartbeat_interval":5,"agent_version":"2.0.0","hardening_enabled":true,"persistence_mode":"aggressive","log_level":"info"} > "C:\Program Files\SecureLab Agent\config.json"
)
echo.
echo Instalando como servicio Windows...
sc create SecureLabAgent binPath= "C:\Program Files\SecureLab Agent\securelab-agent.exe install" start= auto DisplayName= "SecureLab Agent" 2>nul
sc start SecureLabAgent 2>nul
echo.
echo Instalacion completada.
echo.
pause
