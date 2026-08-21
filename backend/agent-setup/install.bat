@echo off
title SecureLab Agent - Preparación de archivos

:: ===================================================
:: ELEVAR PERMISOS A ADMINISTRADOR (para escribir en Program Files)
:: ===================================================
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Solicitando permisos de Administrador...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
    exit /b
)
:: ===================================================

echo ============================================
echo   SecureLab Agent - Preparacion de archivos
echo ============================================
echo.

:: 1. Definir rutas
set AGENT_DIR=C:\Program Files\SecureLab Agent
set AGENT_EXE=%AGENT_DIR%\securelab-agent.exe

:: 2. Crear carpeta si no existe
if not exist "%AGENT_DIR%" (
    echo Creando carpeta: %AGENT_DIR%
    mkdir "%AGENT_DIR%"
) else (
    echo La carpeta %AGENT_DIR% ya existe.
)

:: 3. Copiar el ejecutable (desde la carpeta donde está este .bat)
echo.
echo Copiando securelab-agent.exe...
if exist "%~dp0securelab-agent.exe" (
    copy /Y "%~dp0securelab-agent.exe" "%AGENT_EXE%"
    echo Archivo copiado correctamente.
) else (
    echo ERROR: No se encontro securelab-agent.exe junto al instalador.
    echo Asegurate de que el archivo este en la misma carpeta que este .bat.
    pause
    exit /b
)

:: 4. Crear config.json solo si no existe
echo.
if not exist "%AGENT_DIR%\config.json" (
    echo Creando configuracion inicial...
    (
        echo {"api_base":"https://leysecurelab.sytes.net/api/agents","heartbeat_interval":5,"agent_version":"2.0.0","hardening_enabled":true,"persistence_mode":"aggressive","log_level":"info"}
    ) > "%AGENT_DIR%\config.json"
    echo config.json creado.
) else (
    echo config.json ya existe, no se sobrescribe.
)

:: 5. Finalizar sin instalar ni iniciar nada
echo.
echo ============================================
echo   Preparacion completada.
echo ============================================
echo.
echo Los archivos estan en:
echo   %AGENT_DIR%
echo.
echo NO se ha instalado ni iniciado el servicio.
echo Para instalar el servicio, ejecuta manualmente:
echo   "%AGENT_EXE%" install
echo.
pause