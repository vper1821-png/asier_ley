@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"

set CANDLE="C:\Program Files (x86)\WiX Toolset v3.14\bin\candle.exe"
set LIGHT="C:\Program Files (x86)\WiX Toolset v3.14\bin\light.exe"
set BURN="C:\Program Files (x86)\WiX Toolset v3.14\bin\x86\burn.exe"

echo [1/4] Compilando bundle.wxs...
%CANDLE% -ext WixBalExtension bundle.wxs
if %ERRORLEVEL% neq 0 exit /b 1

echo [2/4] Compilando product.wxs...
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
echo  SECURELAB AGENT EXE GENERADO
echo ============================================
dir SecureLabAgent.exe
echo.
echo Listo para distribuir: SecureLabAgent.exe
echo Un solo archivo, pide UAC, instala silencioso.