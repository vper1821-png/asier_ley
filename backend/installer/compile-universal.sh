#!/bin/bash
# Script universal de compilación - Detecta Linux o Windows
# Linux: NSIS + makensis
# Windows: Inno Setup + ISCC

INSTALLER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$INSTALLER_DIR"

echo "========================================"
echo "  SecureLab Agent - Compilador Universal"
echo "========================================"
echo ""

# Detectar sistema operativo
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    echo "🐧 Detectado: Linux"
    echo "Usando: NSIS + makensis"
    echo ""
    
    # Verificar makensis
    if ! command -v makensis &> /dev/null; then
        echo "❌ makensis no está instalado"
        echo "Instalar con: sudo apt install nsis"
        exit 1
    fi
    
    echo "✓ makensis encontrado"
    
    # Verificar script NSIS
    if [ ! -f "SecureLabAgent.nsi" ]; then
        echo "❌ Script NSIS no encontrado: SecureLabAgent.nsi"
        exit 1
    fi
    
    echo "✓ Script NSIS encontrado"
    echo ""
    echo "Compilando instalador NSIS..."
    makensis SecureLabAgent.nsi
    
    if [ -f "SecureLabAgent-Installer.exe" ]; then
        echo ""
        echo "========================================"
        echo "  ¡Instalador NSIS compilado!"
        echo "========================================"
        ls -lh SecureLabAgent-Installer.exe
    else
        echo "❌ Error: No se generó el instalador"
        exit 1
    fi
    
elif [[ "$OSTYPE" == "msys" || "$OSTYPE" == "win32" ]]; then
    echo "🪟 Detectado: Windows"
    echo "Usando: Inno Setup + ISCC"
    echo ""
    
    # Verificar Inno Setup
    INNO_PATH="${PROGRAMFILES}\\Inno Setup 6\\ISCC.exe"
    if [ ! -f "$INNO_PATH" ]; then
        INNO_PATH="${PROGRAMFILES(X86)}\\Inno Setup 6\\ISCC.exe"
    fi
    
    if [ ! -f "$INNO_PATH" ]; then
        echo "❌ Inno Setup no está instalado"
        echo "Descargar desde: https://jrsoftware.org/isdl.html"
        exit 1
    fi
    
    echo "✓ Inno Setup encontrado: $INNO_PATH"
    
    # Verificar script Inno Setup
    if [ ! -f "SecureLabAgent.iss" ]; then
        echo "❌ Script Inno Setup no encontrado: SecureLabAgent.iss"
        exit 1
    fi
    
    echo "✓ Script Inno Setup encontrado"
    echo ""
    echo "Compilando instalador Inno Setup..."
    "$INNO_PATH" SecureLabAgent.iss
    
    if [ -f "Output/SecureLabAgent-Setup.exe" ]; then
        echo ""
        echo "========================================"
        echo "  ¡Instalador Inno Setup compilado!"
        echo "========================================"
        ls -lh Output/SecureLabAgent-Setup.exe
        echo ""
        echo "Copiar al backend:"
        echo "  copy Output\\SecureLabAgent-Setup.exe ..\\backend\\SecureLabAgent-Installer.exe"
    else
        echo "❌ Error: No se generó el instalador"
        exit 1
    fi
    
else
    echo "❌ Sistema operativo no soportado: $OSTYPE"
    exit 1
fi