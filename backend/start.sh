#!/bin/sh
set -e

# Crear directorios necesarios
mkdir -p /var/run/nginx /run/nginx /var/backups/mongodb /var/log
chown -R www-data:www-data /var/www/html

# Iniciar cron si está disponible (se instalará en próximo rebuild)
if command -v cron >/dev/null 2>&1; then
    service cron start 2>/dev/null || true
fi

# Iniciar PHP-FPM en segundo plano
php-fpm -D

# Iniciar el servidor WebSocket en segundo plano
php /var/www/html/ws-server.php > /var/log/ws-server.log 2>&1 &

# ----------------------------------------------------------------------
# 🔽 NUEVO: Generar el instalador NSIS con el token dinámico
# ----------------------------------------------------------------------
echo "Esperando que el backend esté disponible para obtener token..."
TOKEN=""
MAX_RETRIES=30
COUNT=0
while [ -z "$TOKEN" ] || [ "$TOKEN" = "null" ]; do
    if [ $COUNT -ge $MAX_RETRIES ]; then
        echo "Error: tiempo de espera agotado para obtener el token" >&2
        break
    fi
    sleep 2
    COUNT=$((COUNT+1))
    TOKEN=$(curl -s --fail "http://localhost:3838/generate_agent_config.php" | jq -r '.token' 2>/dev/null || echo "")
done

if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
    echo "Token obtenido: $TOKEN"
    cd /var/www/html/installer
    if command -v makensis >/dev/null 2>&1; then
        mkdir -p output
        makensis -DAGENT_TOKEN="$TOKEN" SecureLabAgent.nsi || echo "Error: falló la compilación del instalador" >&2
        echo "Instalador generado en /var/www/html/installer/output/SecureLabAgent-Installer.exe (si la compilación fue exitosa)"
    else
        echo "Error: makensis no encontrado" >&2
    fi
else
    echo "No se pudo obtener token, no se generará el instalador" >&2
fi
# ----------------------------------------------------------------------

# Iniciar nginx en primer plano
exec nginx -g "daemon off;"