@echo off
title SecureLab Agent Installer v2.0

:: ===================================================
:: ELEVAR PERMISOS A ADMINISTRADOR (evita Error 5)
:: ===================================================
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Solicitando permisos de Administrador...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
    exit /b
)
:: ===================================================

echo ============================================
echo   SecureLab Agent Installer v2.0
echo ============================================
echo.

:: 1. Crear carpeta si no existe
set AGENT_DIR=C:\Program Files\SecureLab Agent
set AGENT_EXE=%AGENT_DIR%\securelab-agent.exe

if not exist "%AGENT_DIR%" mkdir "%AGENT_DIR%"

:: 2. Copiar el ejecutable (desde la carpeta donde está este .bat)
echo Copiando archivos...
copy /Y "%~dp0securelab-agent.exe" "%AGENT_EXE%"
if errorlevel 1 (
    echo ERROR: No se encontro securelab-agent.exe junto al instalador.
    pause
    exit /b
)

:: 3. Crear config.json solo si no existe
if not exist "%AGENT_DIR%\config.json" (
    echo Creando configuracion inicial...
    (
        echo {"api_base":"https://leysecurelab.sytes.net/api/agents","heartbeat_interval":5,"agent_version":"2.0.0","hardening_enabled":true,"persistence_mode":"aggressive","log_level":"info"}
    ) > "%AGENT_DIR%\config.json"
)

:: 4. DESINSTALAR version anterior (si existe) para evitar conflictos
echo.
echo Verificando servicios existentes...
sc query SecureLabAgent >nul 2>&1
if %errorlevel% equ 0 (
    echo El servicio SecureLabAgent ya existe. Desinstalando version anterior...
    sc stop SecureLabAgent >nul 2>&1
    sc delete SecureLabAgent >nul 2>&1
    timeout /t 2 /nobreak >nul
)

:: 5. INSTALAR EL AGENTE USANDO SU PROPIO INSTALADOR (NO sc create)
echo.
echo Instalando el agente como servicio (usando su propio instalador)...
"%AGENT_EXE%" install

:: 6. Verificar si funciono
if %errorlevel% equ 0 (
    echo.
    echo ============================================
    echo   ¡Instalacion completada con exito!
    echo ============================================
    echo.
    echo El agente deberia estar corriendo.
    echo Puedes verificarlo con: sc query SecureLabAgent
    echo (O revisa en servicios.msc)
) else (
    echo.
    echo ============================================
    echo   ERROR: Fallo la instalacion.
    echo ============================================
    echo.
    echo Posibles causas:
    echo - El ejecutable no soporta el comando "install".
    echo - Prueba con: "%AGENT_EXE%" /install
    echo - O con:     "%AGENT_EXE%" -install
    echo.
    echo Si nada funciona, descarga NSSM (Non-Sucking Service Manager)
    echo y usalo para envolver el .exe como servicio.
)

echo.
pause