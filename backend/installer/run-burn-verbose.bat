@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"
set WIX=C:\Users\asier\Music\LA LEY V8\backend\installer\wix311
set BURN="%WIX%\x86\burn.exe"
%BURN% -out SecureLabAgent.exe bundle.wixobj SecureLabAgent.msi -v
echo burn exit: %ERRORLEVEL%
pause