@echo off
curl -s -o test_download.zip -w "HTTP_CODE:%{http_code}" "http://localhost:3838/api/agents/download/win-x64"
echo.
echo Checking file:
dir test_download.zip