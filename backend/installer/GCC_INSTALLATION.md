# Instalar GCC para compilar Fyne en Windows

Fyne requiere CGO y GCC para compilar en Windows. Sigue estos pasos:

## Opción 1: TDM-GCC (Recomendado) ⭐

1. **Descargar TDM-GCC**
   - URL: https://jmeubank.github.io/tdm-gcc/
   - Descarga: `tdm64-gcc-10.3.0-2.exe` o versión más reciente
   - Selecciona "Create" durante la instalación

2. **Instalar**
   - Ejecuta el instalador
   - Selecciona "MinGW-w64 64-bit"
   - Desmarca "Check for updated files on the internet"
   - Click en "Create" y espera la instalación

3. **Verificar instalación**
   ```bash
   gcc --version
   ```
   Debería mostrar algo como: `gcc (tdm64-1) 10.3.0`

## Opción 2: MSYS2

1. **Descargar MSYS2**
   - URL: https://www.msys2.org/
   - Descarga: `msys2-x86_64-20240727.exe`

2. **Instalar**
   - Ejecuta el instalador
   - Selecciona una carpeta (ej: C:\msys64)
   - Completa la instalación

3. **Instalar GCC**
   - Abre "MSYS2 UCRT64" desde el menú de inicio
   - Ejecuta:
     ```bash
     pacman -S mingw-w64-ucrt-x86_64-gcc
     ```

4. **Agregar al PATH**
   - Agrega `C:\msys64\ucrt64\bin` al PATH del sistema

## Opción 3: Usar WSL (Windows Subsystem for Linux)

1. **Instalar WSL**
   - Abre PowerShell como administrador
   - Ejecuta: `wsl --install`
   - Reinicia Windows

2. **Instalar GCC en WSL**
   ```bash
   sudo apt update
   sudo apt install build-essential
   ```

3. **Compilar en WSL**
   - Copia el código a WSL
   - Ejecuta: `CGO_ENABLED=1 go build -o SecureLabAgent-Setup.exe installer.go`

## Después de instalar GCC:

1. **Abrir nueva terminal** (para que cargue las nuevas variables de entorno)

2. **Compilar el instalador**
   ```bash
   cd C:\Users\asier\Music\LA LEY V8\installer
   set CGO_ENABLED=1
   go build -ldflags "-H windowsgui -s -w" -o SecureLabAgent-Setup.exe installer.go
   ```

3. **Verificar**
   Debería generarse `SecureLabAgent-Setup.exe` (~15-20 MB)

## Si no quieres instalar GCC:

Usa **Inno Setup** en su lugar:
- No requiere GCC
- Genera instalador nativo de Windows
- Tiene interfaz gráfica profesional
- Soporta logos
- Más rápido de compilar

Instrucciones en: `LOGO_INSTRUCTIONS.md`