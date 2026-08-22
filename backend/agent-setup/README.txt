SecureLab Agent v2.0 - Instrucciones de Instalación
================================================

ARCHIVOS:
- securelab-agent.exe: Binario del agente (Windows x64)
- install.bat: Script de instalación automática
- config-template.json: Plantilla de configuración (sin token)
- config.json: Configuración con token (generado por el sistema)

INSTALACIÓN AUTOMÁTICA:
1. Ejecutar install.bat como administrador
2. El script instalará el agente en C:\Program Files (x86)\SecureLab\SecureLab Agent
3. Configurará el servicio Windows "SecureLabAgent"
4. El servicio se iniciará automáticamente

INSTALACIÓN MANUAL:
1. Crear directorio: C:\Program Files (x86)\SecureLab\SecureLab Agent
2. Copiar securelab-agent.exe al directorio
3. Copiar config.json (con token generado por el panel) al directorio
4. Ejecutar: securelab-agent.exe install
5. Iniciar servicio: sc start SecureLabAgent

CARACTERÍSTICAS:
- Telemetría CPU/RAM: Envía métricas cada 10 segundos
- Hardening: Auditoría de seguridad (políticas, firewall, updates, BitLocker)
- Persistencia: Instalación automática para arranque persistente
- File Monitor: Vigilancia de directorios (Documents, Desktop, Downloads)
- WebSocket: Conexión en tiempo real con el servidor

VERIFICACIÓN:
- Verificar servicio: sc query SecureLabAgent
- Ver logs: C:\Program Files (x86)\SecureLab\SecureLab Agent\logs\agent.log
- Ver estado en el panel de administración

SOPORTE:
Para problemas de instalación, contacte al administrador del sistema.