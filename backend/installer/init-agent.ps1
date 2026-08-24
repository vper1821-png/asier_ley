# Script de inicialización del agente SecureLab
# Lee el token del archivo token.txt y configura el config.json

$ErrorActionPreference = "Continue"

$InstallDir = "C:\Program Files\SecureLab\SecureLab Agent"
$ConfigFile = "$InstallDir\config.json"
$ScriptDir = $PSScriptRoot

Write-Host "========================================"
Write-Host "  SecureLab Agent - Inicialización"
Write-Host "========================================"
Write-Host ""

# Verificar que el directorio existe
if (-not (Test-Path $InstallDir)) {
    Write-Host "ERROR: Directorio de instalación no encontrado: $InstallDir"
    Write-Host ""
    Write-Host "El script se cerrará en 10 segundos..."
    Start-Sleep -Seconds 10
    exit 1
}

Write-Host "Directorio encontrado: $InstallDir"
Write-Host ""

# Buscar archivo de token en el directorio del script
$TokenFile = "$ScriptDir\token.txt"
if (-not (Test-Path $TokenFile)) {
    Write-Host "AVISO: Archivo de token no encontrado: $TokenFile"
    Write-Host "Se usará el token del config.json existente"
    Write-Host ""
    
    # Intentar leer el config existente
    if (Test-Path $ConfigFile) {
        $Config = Get-Content $ConfigFile | ConvertFrom-Json
        if ($Config.token -ne "TOKEN_DEL_AGENTE") {
            Write-Host "Config ya tiene token configurado"
            Write-Host "Reiniciando servicio..."
            Restart-Service -Name "SecureLabAgent" -Force -ErrorAction SilentlyContinue
            Write-Host "Completado"
            Start-Sleep -Seconds 5
            exit 0
        }
    }
    
    Write-Host "El script se cerrará en 10 segundos..."
    Start-Sleep -Seconds 10
    exit 0
}

Write-Host "Archivo de token encontrado"
Write-Host ""

# Leer token
$Token = Get-Content $TokenFile -Raw
$Token = $Token.Trim()

Write-Host "Token obtenido: $($Token.Substring(0, [Math]::Min(50, $Token.Length)))..."
Write-Host ""

try {
    Write-Host "Actualizando config.json con el token..."
    
    # Leer config existente
    $Config = Get-Content $ConfigFile | ConvertFrom-Json
    
    # Actualizar token
    $Config.token = $Token
    
    # Guardar
    $Config | ConvertTo-Json -Depth 10 | Set-Content $ConfigFile
    
    Write-Host "Config actualizado con token"
    Write-Host ""
    Write-Host "Reiniciando servicio..."
    
    # Reiniciar servicio
    Restart-Service -Name "SecureLabAgent" -Force -ErrorAction SilentlyContinue
    
    Write-Host ""
    Write-Host "========================================"
    Write-Host "  Completado!"
    Write-Host "========================================"
    
    # Eliminar archivo de token después de usarlo
    Remove-Item $TokenFile -Force -ErrorAction SilentlyContinue
    
} catch {
    Write-Host "ERROR: $($_.Exception.Message)"
    Write-Host ""
    Write-Host "El script se cerrará en 10 segundos..."
    Start-Sleep -Seconds 10
    exit 1
}

Write-Host ""
Write-Host "El script se cerrará en 5 segundos..."
Start-Sleep -Seconds 5