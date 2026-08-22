@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"
set WIX=C:\Users\asier\Music\LA LEY V8\backend\installer\wix311
set BURN="%WIX%\x86\burn.exe"
set CANDLE="%WIX%\candle.exe"

echo Testing burn with -v -v -v...
%BURN% -out test.exe test-bundle.wixobj SecureLabAgent.msi -v -v -v 2>&1
echo burn exit: %ERRORLEVEL%
pause