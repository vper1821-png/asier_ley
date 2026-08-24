# SecureLab Agent

Agente de seguridad para el cumplimiento de la Ley 21.719 de Protección de Datos Personales de Chile.

## Características

- Monitoreo de bases de datos (MySQL, PostgreSQL, MSSQL, MongoDB, Redis, SQLite)
- Detección de datos personales en tablas, columnas y archivos
- Monitoreo de archivos (lectura, copia, modificación, eliminación) con registro de usuario y proceso
- Hardening automático: políticas de contraseñas, firewall, cifrado, actualizaciones
- Bloqueo de usuarios e IPs
- Asistente IA con conocimiento de la ley
- Comunicación vía WebSocket y API REST

## Instalación

```bash
go build -o securelab-agent cmd/agent/main.go
./securelab-agent install   # Windows