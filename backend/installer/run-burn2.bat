@echo off
cd /d "C:\Users\asier\Music\LA LEY V8\backend\installer"
"C:\Program Files (x86)\WiX Toolset v3.14\bin\x86\burn.exe" -out SecureLabAgent.exe bundle.wixobj SecureLabAgent.msi -v
echo burn exit: %ERRORLEVEL%
pause