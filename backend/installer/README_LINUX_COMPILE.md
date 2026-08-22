# Compilar Instalador Windows desde Linux

## Requisitos

### En Linux:
```bash
sudo apt update
sudo apt install nsis
```

## Archivos Necesarios

En la carpeta `installer/` necesitas:

### Obligatorios:
- ✅ `SecureLabAgent.nsi` - Script NSIS
- ✅ `LICENSE.txt` - Licencia del instalador
- ✅ `securelab-agent.exe` - Binario del agente (copia desde `backend/agent-bin/`)

### Opcionales (para logos):
- `installer-logo.bmp` (164×314)
- `installer-small.bmp` (55×55)

## Preparar el Binario del Agente

### Desde Windows:
```bash
copy backend\agent-bin\securelab-agent-win-x64.exe installer\securelab-agent.exe
```

### Desde Linux (si tienes acceso):
```bash
cp backend/agent-bin/securelab-agent-win-x64.exe installer/securelab-agent.exe
```

## Compilar

### Opción 1: Usar el script (Recomendado)
```bash
cd installer
chmod +x compile-linux.sh
./compile-linux.sh
```

### Opción 2: Directo con makensis
```bash
cd installer
makensis SecureLabAgent.nsi
```

## Resultado

Se generará: `SecureLabAgent-Installer.exe`

Este instalador:
- ✅ Funciona en Windows
- ✅ Solicita UAC (privilegios de administrador)
- ✅ Instala servicio Windows
- ✅ Inicia servicio automáticamente
- ✅ Se puede desinstalar desde "Programas y características"

## Copiar al Servidor

```bash
docker cp installer/SecureLabAgent-Installer.exe invisia-backend-php:/var/www/html/SecureLabAgent-Setup.exe
```

## Solución de Problemas

### Error: makensis: command not found
```bash
sudo apt install nsis
```

### Error: File "securelab-agent.exe" not found
Copia el binario desde `backend/agent-bin/securelab-agent-win-x64.exe`

### Error: can't open "LICENSE.txt"
Asegúrate de que el archivo LICENSE.txt exista en la carpeta

## Arquitectura Recomendada

- **Windows**: NSIS Installer (este script)
- **Linux/K8s**: Docker Container + DaemonSet (sin instalador)