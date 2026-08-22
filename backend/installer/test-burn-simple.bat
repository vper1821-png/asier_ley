@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"
set WIX=C:\Users\asier\Music\LA LEY V8\backend\installer\wix311
set BURN="%WIX%\x86\burn.exe"
set CANDLE="%WIX%\candle.exe"

echo Testing burn version...
%BURN% -version

echo.
echo Compiling test bundle...
%CANDLE% -ext WixBalExtension test-bundle.wxs
if %ERRORLEVEL% neq 0 exit /b 1

echo Running burn...
%BURN% -out test.exe test-bundle.wixobj SecureLabAgent.msi -v
echo burn exit: %ERRORLEVEL%

pause