# SecureLab Agent - Compilador Universal

## � Sistema de compilación inteligente

Este sistema detecta automáticamente el entorno y usa el instalador correspondiente:

| Entorno | Sistema | Compilador | Script |
|---------|---------|------------|--------|
| **Linux** | NSIS | makensis | SecureLabAgent.nsi |
| **Windows** | Inno Setup | ISCC.exe | SecureLabAgent.iss |

---

## 🚀 Uso

### En Linux:
```bash
cd installer
chmod +x compile-universal.sh
./compile-universal.sh
```

### En Windows (PowerShell):
```powershell
cd installer
.\compile-universal.ps1
```

### En Windows (Git Bash/MSYS2):
```bash
cd installer
./compile-universal.sh
```

---

## � Archivos

### Instalador NSIS (Linux/Windows con makensis):
- **Script**: `SecureLabAgent.nsi`
- **Compilador**: `makensis`
- **Salida**: `SecureLabAgent-Installer.exe`
- **Logos**: Icono ICO (sin logo lateral en makensis)

### Instalador Inno Setup (Windows):
- **Script**: `SecureLabAgent.iss`
- **Compilador**: ISCC.exe
- **Salida**: `Output/SecureLabAgent-Setup.exe`
- **Logos**: BMP lateral y superior (interfaz gráfica completa)

---

## 🔧 Instalación de dependencias

### Linux:
```bash
sudo apt update
sudo apt install nsis
```

### Windows:
- Descargar Inno Setup: https://jrsoftware.org/isdl.html
- Instalar con opciones por defecto

---

## 📋 Comparación

| Característica | NSIS (makensis) | Inno Setup |
|----------------|-----------------|------------|
| **Compilación en Linux** | ✅ | ❌ |
| **Compilación en Windows** | ✅ | ✅ |
| **Logo lateral** | ❌ | ✅ |
| **Logo superior** | ✅ (icono) | ✅ |
| **Interfaz gráfica** | ✅ | ✅ |
| **UAC** | ✅ | ✅ |
| **Tamaño** | Similar | Similar |

---

## 🎨 Características del instalador

Ambos instaladores incluyen:
- ✅ Privilegios de administrador (UAC)
- ✅ Instalación de servicio Windows
- ✅ Inicio automático del servicio
- ✅ Desinstalador incluido
- ✅ Registro en "Programas y características"
- ✅ Idioma español

---

## 🔄 Flujo de trabajo

### Desarrollar en Linux:
```bash
./compile-universal.sh
# Genera: SecureLabAgent-Installer.exe (NSIS)
```

### Desarrollar en Windows:
```powershell
.\compile-universal.ps1
# Genera: SecureLabAgent-Installer.exe (Inno Setup)
# Copia automáticamente al backend
# Reinicia contenedor Docker si está disponible
```

---

## � Notas

- **Inno Setup** tiene mejor soporte de gráficos (logo lateral BMP)
- **NSIS** es más portable (funciona en Linux y Windows)
- Ambos generan instaladores funcionales para Windows
- El script universal elige automáticamente el mejor para tu entorno