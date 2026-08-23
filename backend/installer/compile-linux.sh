#!/bin/bash
# Script de compilación del instalador NSIS para Windows desde Linux
# Uso: ./compile-linux.sh

set -e

echo "========================================"
echo "  SecureLab Agent - Compilador Linux"
echo "========================================"
echo ""

# Directorio del instalador y backend
INSTALLER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$INSTALLER_DIR/.." && pwd)"
AGENT_SRC_DIR="$BACKEND_DIR/securelab-agent"

# Verificar que Go está instalado
if ! command -v go &> /dev/null; then
    echo "❌ Go no está instalado"
    exit 1
fi
echo "✓ Go encontrado: $(go version)"

# Verificar que makensis está instalado
if ! command -v makensis &> /dev/null; then
    echo "❌ makensis no está instalado"
    echo "Instalar con: sudo apt install nsis"
    exit 1
fi
echo "✓ makensis encontrado: $(makensis -VERSION)"
echo ""

# 1. Compilar el agente en Go para Windows (amd64)
echo "Compilando binario de Windows (GOOS=windows GOARCH=amd64)..."
mkdir -p "$BACKEND_DIR/agent-bin"
cd "$AGENT_SRC_DIR"
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags "-s -w" -o "$INSTALLER_DIR/securelab-agent.exe" ./cmd/agent
cp "$INSTALLER_DIR/securelab-agent.exe" "$BACKEND_DIR/agent-bin/securelab-agent-win-x64.exe"
cp "$INSTALLER_DIR/securelab-agent.exe" "$BACKEND_DIR/agent-bin/securelab-agent-win-x64"
echo "✓ Binario Windows compilado: $INSTALLER_DIR/securelab-agent.exe"
echo ""

# 2. Compilar el instalador NSIS
cd "$INSTALLER_DIR"
echo "Verificando archivos del instalador..."
FILES_NEEDED=("SecureLabAgent.nsi" "LICENSE.txt" "securelab-agent.exe")
for file in "${FILES_NEEDED[@]}"; do
    if [ ! -f "$file" ]; then
        echo "❌ Falta archivo: $file"
        exit 1
    fi
    echo "  ✓ $file"
done

echo ""
echo "Compilando instalador NSIS..."
makensis SecureLabAgent.nsi

if [ -f "SecureLabAgent-Installer.exe" ]; then
    cp SecureLabAgent-Installer.exe "$BACKEND_DIR/SecureLabAgent-Installer.exe"
    mkdir -p Output
    cp SecureLabAgent-Installer.exe Output/SecureLabAgent-Setup.exe
    
    echo ""
    echo "========================================"
    echo "  ¡Instalador NSIS compilado con éxito!"
    echo "========================================"
    echo ""
    echo "Archivo generado: SecureLabAgent-Installer.exe"
    ls -lh SecureLabAgent-Installer.exe
    echo ""
    echo "Características del instalador NSIS:"
    echo "  ✓ Ejecutable .exe autónomo para Windows x64"
    echo "  ✓ Requiere elevación de privilegios (UAC)"
    echo "  ✓ Configura config.json automáticamente"
    echo "  ✓ Registra e inicia el servicio Windows SecureLabAgent"
    echo "  ✓ Configura auto-recuperación ante fallos del servicio"
    echo "  ✓ Crea desinstalador completo en Panel de Control"
    echo ""
else
    echo "❌ Error: No se generó el instalador NSIS"
    exit 1
fi
