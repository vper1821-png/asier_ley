# Compilar Instalador NSIS - Instrucciones

## 🎯 Estado Actual

### ✅ Preparado:
- ✅ Script NSIS modificado (compatible con makensis Linux)
- ✅ Logos BMP convertidos
- ✅ Iconos ICO convertidos
- ✅ LICENSE.txt creado
- ✅ Binario del agente copiado
- ✅ Backend actualizado para devolver NSIS
- ✅ Frontend actualizado para descargar NSIS

### ⚠️ Pendiente:
- ⚠️ Compilar el instalador NSIS (requiere makensis o Inno Setup)

---

## 🚀 **Opción 1: Compilar desde Linux (Recomendado)**

### 1. Copiar archivos a Linux
```bash
# Copiar carpeta installer a tu máquina Linux
scp -r installer/ usuario@linux-server:/tmp/
```

### 2. En Linux:
```bash
cd /tmp/installer

# Instalar NSIS (si no está instalado)
sudo apt update
sudo apt install nsis

# Compilar
makensis SecureLabAgent.nsi
```

### 3. Copiar instalador de vuelta
```bash
scp SecureLabAgent-Installer.exe usuario@windows-server:/tmp/
```

### 4. En Windows, copiar al backend:
```bash
docker cp SecureLabAgent-Installer.exe backend/SecureLabAgent-Installer.exe
docker restart invisia-backend-php
```

---

## 🚀 **Opción 2: Compilar desde Windows**

### 1. Descargar Inno Setup
- URL: https://jrsoftware.org/isdl.html
- Descarga: `innosetup-6.3.3.exe` o versión más reciente
- Instala con opciones por defecto

### 2. Ejecutar script de compilación
```powershell
cd C:\Users\asier\Music\LA LEY V8\installer
.\compile-windows.ps1
```

### 3. Copiar al backend
```powershell
copy SecureLabAgent-Installer.exe ..\backend\SecureLabAgent-Installer.exe
docker cp SecureLabAgent-Installer.exe invisia-backend-php:/var/www/html/SecureLabAgent-Installer.exe
docker restart invisia-backend-php
```

---

## 📋 **Después de compilar:**

### El instalador NSIS tiene:
- ✅ Logo BMP integrado
- ✅ Privilegios de administrador (UAC)
- ✅ Interfaz gráfica nativa de Windows
- ✅ Desinstalador incluido
- ✅ Registro en "Programas y características"

### El botón en /agents:
- Descarga: `SecureLabAgent-Installer.exe`
- Funciona en Windows con admin
- Solicita UAC automáticamente

---

## 🔄 **Alternativa: Usar instalador de Go (actual)**

Si no quieres compilar NSIS, el instalador de Go ya está listo:
- ✅ Tiene interfaz visual (Fyne GUI)
- ✅ Logo visual
- ✅ Barra de progreso
- ✅ Ya está en el backend

Para usar el de Go, solo cambia en el backend:
```php
// En agents.php línea 474
$installerPath = __DIR__ . '/../SecureLabAgent-Setup.exe'; // En lugar de SecureLabAgent-Installer.exe
```