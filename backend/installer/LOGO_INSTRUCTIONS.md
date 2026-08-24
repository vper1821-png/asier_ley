# Instrucciones para agregar Logo al Instalador

## Imágenes necesarias:

### 1. **WizardImageFile** (Logo grande lateral)
- **Nombre**: `installer-logo.bmp`
- **Dimensiones**: 164 × 314 píxeles
- **Formato**: BMP (Windows Bitmap)
- **Ubicación**: Aparece en el lado izquierdo del asistente de instalación

### 2. **WizardSmallImageFile** (Logo pequeño superior)
- **Nombre**: `installer-small.bmp`
- **Dimensiones**: 55 × 55 píxeles
- **Formato**: BMP (Windows Bitmap)
- **Ubicación**: Aparece en la esquina superior izquierda de cada página

## Cómo crear las imágenes:

### Opción 1: Convertir desde PNG/JPG
```bash
# Usando ImageMagick (si está instalado)
convert logo.png -resize 164x314 installer-logo.bmp
convert logo.png -resize 55x55 installer-small.bmp
```

### Opción 2: Usar Paint o GIMP
1. Abre tu logo en Paint/GIMP
2. Redimensiona a las dimensiones especificadas
3. Guarda como BMP (24-bit o 32-bit)
4. Guarda como `installer-logo.bmp` y `installer-small.bmp`

### Opción 3: Usar herramienta online
- https://convertio.co/es/png-bmp/
- Sube tu PNG, convierte a BMP
- Redimensiona a las dimensiones correctas

## Ubicación:
Coloca los archivos BMP en la carpeta del instalador:
```
LA LEY V8/installer/
├── installer-logo.bmp      ← Logo grande (164x314)
├── installer-small.bmp     ← Logo pequeño (55x55)
├── SecureLabAgent.iss
└── SecureLabAgent.nsi
```

## Compilar el instalador:

### Con Inno Setup:
1. Descarga Inno Setup: https://jrsoftware.org/isdl.php
2. Abre `SecureLabAgent.iss` con Inno Setup Compiler
3. Click en "Compile"
4. Se generará `SecureLabAgent-Setup.exe`

### Nota importante:
- Las imágenes deben estar en formato BMP
- Si no tienes las imágenes, el instalador funcionará sin logo
- Para usar el instalador, necesitas copiar también el binario del agente (`securelab-agent.exe`) en la carpeta del instalador