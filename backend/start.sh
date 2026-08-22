#!/bin/sh
set -e

# Crear directorios necesarios
mkdir -p /var/run/nginx /run/nginx /var/backups/mongodb /var/log
chown -R www-data:www-data /var/www/html

# Iniciar cron si está disponible
if command -v cron >/dev/null 2>&1; then
    service cron start 2>/dev/null || true
fi

# Iniciar PHP-FPM en segundo plano
php-fpm -D

# Iniciar el servidor WebSocket en segundo plano
php /var/www/html/ws-server.php > /var/log/ws-server.log 2>&1 &

# ----------------------------------------------------------------------
# 🔽 Generar el instalador NSIS en segundo plano (desacoplado)
# ----------------------------------------------------------------------
(
    # Esperar a que el endpoint esté disponible (máximo 30s)
    echo "Esperando que el backend esté disponible para obtener token..."
    TOKEN=""
    MAX_RETRIES=15
    COUNT=0
    ENDPOINT_URL="http://localhost:3838/generate_agent_config.php"

    while [ $COUNT -lt $MAX_RETRIES ]; do
        # Verificar que el endpoint responda con HTTP 200
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$ENDPOINT_URL" 2>/dev/null)
        if [ "$HTTP_CODE" = "200" ]; then
            TOKEN=$(curl -s "$ENDPOINT_URL" | jq -r '.token' 2>/dev/null)
            if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
                echo "Token obtenido correctamente: $TOKEN"
                break
            else
                echo "El endpoint respondió pero no se pudo extraer el token" >&2
            fi
        else
            echo "Esperando que el endpoint esté listo... (código HTTP: $HTTP_CODE, intento $((COUNT+1))/$MAX_RETRIES)"
        fi
        sleep 2
        COUNT=$((COUNT+1))
    done

    if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
        echo "Token obtenido: $TOKEN"
        cd /var/www/html/installer
        if command -v makensis >/dev/null 2>&1; then
            mkdir -p output
            makensis -DAGENT_TOKEN="$TOKEN" SecureLabAgent.nsi
            # Mover el instalador a output/ (donde lo espera download())
            if [ -f SecureLabAgent-Installer.exe ]; then
                mv SecureLabAgent-Installer.exe output/
                echo "Instalador generado en /var/www/html/installer/output/SecureLabAgent-Installer.exe"
            else
                echo "Error: no se generó el archivo SecureLabAgent-Installer.exe" >&2
            fi
        else
            echo "Error: makensis no encontrado" >&2
        fi
    else
        echo "No se pudo obtener token después de $MAX_RETRIES intentos, no se generará el instalador" >&2
    fi
) &
# ----------------------------------------------------------------------

# Iniciar nginx en primer plano (esto hace que el pod esté "ready" inmediatamente)
exec nginx -g "daemon off;"