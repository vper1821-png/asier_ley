#!/bin/bash
# Script de compilación del instalador NSIS para Windows desde Linux
# Uso: ./compile-linux.sh

set -e

echo "========================================"
echo "  SecureLab Agent - Compilador Linux"
echo "========================================"
echo ""

# Verificar que makensis está instalado
if ! command -v makensis &> /dev/null; then
    echo "❌ makensis no está instalado"
    echo "Instalar con: sudo apt install nsis"
    exit 1
fi

echo "✓ makensis encontrado: $(makensis -VERSION)"
echo ""

# Directorio del instalador
INSTALLER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$INSTALLER_DIR"

# Verificar archivos necesarios
echo "Verificando archivos necesarios..."
FILES_NEEDED=("SecureLabAgent.nsi" "LICENSE.txt")
for file in "${FILES_NEEDED[@]}"; do
    if [ ! -f "$file" ]; then
        echo "❌ Falta archivo: $file"
        exit 1
    fi
    echo "  ✓ $file"
done

# Verificar logos (opcionales)
if [ -f "installer-logo.bmp" ]; then
    echo "  ✓ installer-logo.bmp"
else
    echo "  ⚠ installer-logo.bmp (opcional, no encontrado)"
fi

if [ -f "installer-small.bmp" ]; then
    echo "  ✓ installer-small.bmp"
else
    echo "  ⚠ installer-small.bmp (opcional, no encontrado)"
fi

echo ""
echo "Compilando instalador..."
makensis SecureLabAgent.nsi

if [ -f "SecureLabAgent-Installer.exe" ]; then
    echo ""
    echo "========================================"
    echo "  ¡Instalador compilado exitosamente!"
    echo "========================================"
    echo ""
    echo "Archivo generado: SecureLabAgent-Installer.exe"
    ls -lh SecureLabAgent-Installer.exe
    echo ""
    echo "Este instalador:"
    echo "  ✓ Funciona en Windows"
    echo "  ✓ Solicita privilegios de administrador (UAC)"
    echo "  ✓ Instala el servicio Windows"
    echo "  ✓ Inicia el servicio automáticamente"
    echo ""
else
    echo ""
    echo "❌ Error: No se generó el instalador"
    exit 1
fi