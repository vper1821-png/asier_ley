# Script de compilación NSIS para Windows
# Requiere Inno Setup: https://jrsoftware.org/isdl.html

# Verificar si Inno Setup está instalado
$innoSetupPath = "$env:ProgramFiles(x86)\Inno Setup 6\ISCC.exe"
if (-not (Test-Path $innoSetupPath)) {
    Write-Host "❌ Inno Setup no está instalado"
    Write-Host "Descargar desde: https://jrsoftware.org/isdl.html"
    exit 1
}

Write-Host "✓ Inno Setup encontrado: $innoSetupPath"
Write-Host ""

# Directorio del instalador
$installerDir = "C:\Users\asier\Music\LA LEY V8\installer"
Set-Location $installerDir

# Verificar archivos necesarios
$filesNeeded = @("SecureLabAgent.nsi", "LICENSE.txt", "securelab-agent.exe")
foreach ($file in $filesNeeded) {
    if (-not (Test-Path $file)) {
        Write-Host "❌ Falta archivo: $file"
        exit 1
    }
    Write-Host "  ✓ $file"
}

Write-Host ""
Write-Host "Compilando instalador..."
& $innoSetupPath SecureLabAgent.nsi

if (Test-Path "SecureLabAgent-Installer.exe") {
    Write-Host ""
    Write-Host "========================================"
    Write-Host "  ¡Instalador compilado exitosamente!"
    Write-Host "========================================"
    Write-Host ""
    Write-Host "Archivo generado: SecureLabAgent-Installer.exe"
    Write-Host ""
    Write-Host "Copiar al backend:"
    Write-Host "  docker copy installer\SecureLabAgent-Installer.exe backend\SecureLabAgent-Installer.exe"
} else {
    Write-Host "❌ Error: No se generó el instalador"
}