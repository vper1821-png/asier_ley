@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"

set SOURCE="C:\Users\asier\Music\LA LEY V8\backend\securelab-agent\securelab-agent.exe"
set CONFIG="C:\Users\asier\Music\LA LEY V8\backend\installer\config.json"
set INSTALL_BAT="C:\Users\asier\Music\LA LEY V8\backend\installer\Install-SecureLabAgent.bat"
set INSTALL_PS1="C:\Users\asier\Music\LA LEY V8\backend\installer\Install-SecureLabAgent.ps1"
set README="C:\Users\asier\Music\LA LEY V8\backend\installer\README.txt"
set OUT_DIR="C:\Users\asier\Music\LA LEY V8\backend\installer"

if not exist %CONFIG% (
    echo {"api_base":"http://localhost:3838/api/agents","heartbeat_interval":5,"agent_version":"2.0.0","hardening_enabled":true,"persistence_mode":"aggressive","log_level":"info"} > %CONFIG%
)

echo [1/3] Copiando archivos a staging...
set STAGING=%TEMP%\securelab-staging
if exist %STAGING% rmdir /S /Q %STAGING%
mkdir %STAGING%

set INNER=%STAGING%\SecureLabAgent-Windows
mkdir %INNER%

copy /Y %SOURCE% %INNER%\securelab-agent.exe
if %ERRORLEVEL% neq 0 exit /b 1
copy /Y %CONFIG% %INNER%\config.json
copy /Y %INSTALL_BAT% %INNER%\Install-SecureLabAgent.bat
copy /Y %INSTALL_PS1% %INNER%\Install-SecureLabAgent.ps1
copy /Y %README% %INNER%\README.txt

echo [2/3] Generando ZIP...
powershell -NoProfile -Command "Compress-Archive -Path '%STAGING%\SecureLabAgent-Windows' -DestinationPath '%OUT_DIR%\SecureLabAgent-Windows.zip' -Force"
if %ERRORLEVEL% neq 0 exit /b 1

echo [3/3] Limpiando staging...
rmdir /S /Q %STAGING%

echo.
echo ZIP generado: %OUT_DIR%\SecureLabAgent-Windows.zip
dir %OUT_DIR%\SecureLabAgent-Windows.zip
