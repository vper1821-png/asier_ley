@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"

set WIX=C:\Users\asier\Music\LA LEY V8\backend\installer\wix311
set CANDLE="%WIX%\candle.exe"
set LIGHT="%WIX%\light.exe"
set BURN="%WIX%\x86\burn.exe"

echo [1/4] Compilando bundle.wxs con WiX 3.11...
%CANDLE% -ext WixBalExtension bundle.wxs
if %ERRORLEVEL% neq 0 exit /b 1

echo [2/4] Compilando product.wxs con WiX 3.11...
%CANDLE% -dExeSource="C:\Users\asier\Music\LA LEY V8\backend\installer\securelab-agent.exe" -dConfigSource="C:\Users\asier\Music\LA LEY V8\backend\installer\config.json" product.wxs
if %ERRORLEVEL% neq 0 exit /b 1

echo [3/4] Linkeando MSI...
%LIGHT% -ext WixUtilExtension -out SecureLabAgent.msi product.wixobj
if %ERRORLEVEL% neq 0 exit /b 1

echo [4/4] Generando EXE (Burn)...
%BURN% -out SecureLabAgent.exe bundle.wixobj SecureLabAgent.msi
if %ERRORLEVEL% neq 0 exit /b 1

echo.
echo ============================================
echo  SECURELAB AGENT EXE GENERADO CON WIX 3.11
echo ============================================
dir SecureLabAgent.exe
echo.
echo Listo para distribuir: SecureLabAgent.exe
echo Un solo archivo, pide UAC, instala silencioso.