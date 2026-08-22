# Script universal de compilacion para Windows PowerShell
# Detecta entorno y usa el compilador apropiado

$installerDir = "C:\Users\asier\Music\LA LEY V8\installer"
Set-Location $installerDir

Write-Host "========================================"
Write-Host "  SecureLab Agent - Compilador Universal"
Write-Host "========================================"
Write-Host ""
Write-Host "Detectado: Windows"
Write-Host "Usando: Inno Setup + ISCC"
Write-Host ""

# Verificar Inno Setup
$innoPath = "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
if (-not (Test-Path $innoPath)) {
    $innoPath = "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe"
}

if (-not (Test-Path $innoPath)) {
    Write-Host "ERROR: Inno Setup no esta instalado"
    Write-Host "Descargar desde: https://jrsoftware.org/isdl.html"
    exit 1
}

Write-Host "Inno Setup encontrado: $innoPath"

# Verificar script Inno Setup
if (-not (Test-Path "SecureLabAgent.iss")) {
    Write-Host "ERROR: Script Inno Setup no encontrado: SecureLabAgent.iss"
    exit 1
}

Write-Host "Script Inno Setup encontrado"
Write-Host ""
Write-Host "Compilando instalador Inno Setup..."
& $innoPath SecureLabAgent.iss

if (Test-Path "Output\SecureLabAgent-Setup.exe") {
    Write-Host ""
    Write-Host "========================================"
    Write-Host "  Instalador Inno Setup compilado!"
    Write-Host "========================================"
    Get-Item "Output\SecureLabAgent-Setup.exe" | Format-Table Name, Length -AutoSize
    Write-Host ""
    Write-Host "Copiar al backend:"
    Write-Host "  copy Output\SecureLabAgent-Setup.exe ..\backend\SecureLabAgent-Installer.exe"
    
    # Copiar automaticamente
    Copy-Item "Output\SecureLabAgent-Setup.exe" "..\backend\SecureLabAgent-Installer.exe"
    Write-Host ""
    Write-Host "Copiado al backend"
    
    # Copiar al contenedor Docker si esta disponible
    try {
        docker cp "..\backend\SecureLabAgent-Installer.exe" invisia-backend-php:/var/www/html/SecureLabAgent-Installer.exe
        Write-Host "Copiado al contenedor Docker"
        docker restart invisia-backend-php
        Write-Host "Contenedor reiniciado"
    } catch {
        Write-Host "Contenedor Docker no disponible (normal si no estas en Docker)"
    }
} else {
    Write-Host "ERROR: No se genero el instalador"
    exit 1
}