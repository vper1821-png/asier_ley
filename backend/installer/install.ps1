# Script de instalación del agente SecureLab
# Extraer el ZIP y ejecutar este script como administrador

$ErrorActionPreference = "Stop"

$ScriptDir = $PSScriptRoot
$InstallDir = "C:\Program Files\SecureLab\SecureLab Agent"

Write-Host "========================================"
Write-Host "  SecureLab Agent - Instalación"
Write-Host "========================================"
Write-Host ""

# Verificar privilegios de administrador
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "ERROR: Este script debe ejecutarse como Administrador"
    Write-Host "Click derecho -> Ejecutar como Administrador"
    Read-Host "Presiona Enter para salir"
    exit 1
}

Write-Host "Privilegios de administrador: OK"
Write-Host ""

# Verificar archivos necesarios
if (-not (Test-Path "$ScriptDir\securelab-agent.exe")) {
    Write-Host "ERROR: securelab-agent.exe no encontrado"
    exit 1
}

if (-not (Test-Path "$ScriptDir\config.json")) {
    Write-Host "ERROR: config.json no encontrado"
    exit 1
}

Write-Host "Archivos encontrados"
Write-Host ""

# Crear directorio de instalación
Write-Host "Creando directorio: $InstallDir"
New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null

# Copiar archivos
Write-Host "Copiando archivos..."
Copy-Item "$ScriptDir\securelab-agent.exe" "$InstallDir\" -Force
Copy-Item "$ScriptDir\config.json" "$InstallDir\" -Force

# Crear directorio de logs
New-Item -ItemType Directory -Path "$InstallDir\logs" -Force | Out-Null

Write-Host "Archivos copiados"
Write-Host ""

# Detener servicio si existe
Write-Host "Deteniendo servicio existente (si existe)..."
try {
    Stop-Service -Name "SecureLabAgent" -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    sc.exe delete "SecureLabAgent" | Out-Null
} catch {
    # Ignorar errores si el servicio no existe
}

# Crear servicio
Write-Host "Creando servicio Windows..."
sc.exe create "SecureLabAgent" binPath= "$InstallDir\securelab-agent.exe --config $InstallDir\config.json" start= auto DisplayName= "SecureLab Agent" | Out-Null
sc.exe description "SecureLabAgent" "SecureLab Monitoring Agent" | Out-Null

Write-Host "Servicio creado"
Write-Host ""

# Iniciar servicio
Write-Host "Iniciando servicio..."
sc.exe start "SecureLabAgent" | Out-Null

Write-Host ""
Write-Host "========================================"
Write-Host "  Instalación completada!"
Write-Host "========================================"
Write-Host ""
Write-Host "El agente está corriendo y configurado con tu token."
Write-Host ""
Write-Host "Para verificar el estado:"
Write-Host "  sc query SecureLabAgent"
Write-Host ""
Write-Host "Este script se cerrará en 5 segundos..."
Start-Sleep -Seconds 5