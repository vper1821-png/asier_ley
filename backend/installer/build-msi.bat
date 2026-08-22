@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"

set CANDLE="C:\Program Files (x86)\WiX Toolset v3.14\bin\candle.exe"
set LIGHT="C:\Program Files (x86)\WiX Toolset v3.14\bin\light.exe"

set ExeSource="C:\Users\asier\Music\LA LEY V8\backend\installer\securelab-agent.exe"
set ConfigSource="C:\Users\asier\Music\LA LEY V8\backend\installer\config.json"

echo [1/3] Compilando .wixobj...
%CANDLE% -dExeSource=%ExeSource% -dConfigSource=%ConfigSource% product.wxs
if %ERRORLEVEL% neq 0 exit /b 1

echo [2/3] Generando MSI...
%LIGHT% -ext WixUtilExtension -out SecureLabAgent.msi product.wixobj
if %ERRORLEVEL% neq 0 exit /b 1

echo [3/3] MSI generado: SecureLabAgent.msi
dir SecureLabAgent.msi