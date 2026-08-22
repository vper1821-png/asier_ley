@echo off
chcp 65001 >nul

:: 1. Matar procesos
 taskkill /F /IM msiexec.exe 2>nul
taskkill /F /IM SecureLabAgent.exe 2>nul

:: 2. Eliminar servicio
sc stop SecureLabAgent 2>nul
sc delete SecureLabAgent 2>nul

:: 3. Desinstalar todos los SecureLab Agent huérfanos (silencioso)
for /f "tokens=*" %%i in ('powershell -NoProfile -Command "Get-WmiObject -Class Win32_Product -Filter \"Name = 'SecureLab Agent'\" | Select-Object -ExpandProperty IdentifyingNumber"') do (
    echo Desinstalando %%i
    msiexec /x %%i /qn /norestart
)

:: 4. Limpiar rastros de instalación anterior
rmdir /S /Q "C:\Program Files\SecureLab Agent" 2>nul
rmdir /S /Q "C:\ProgramData\SecureLab Agent" 2>nul

:: 5. Instalar el nuevo MSI v2.0.1 con ProductID fijo
msiexec /i "%~dp0SecureLabAgent.msi" /qn

echo.
echo Instalacion completada. Iniciando servicio...
sc start SecureLabAgent 2>nul
echo.
echo Si el servicio no inicio, ejecuta manualmente: sc start SecureLabAgent
pause