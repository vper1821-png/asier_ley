#!/bin/sh
set -e

# Crear directorios necesarios
mkdir -p /var/run/nginx /run/nginx
chown -R www-data:www-data /var/www/html

# Iniciar PHP-FPM en segundo plano
php-fpm -D

# Iniciar el servidor WebSocket en segundo plano
php /var/www/html/ws-server.php > /var/log/ws-server.log 2>&1 &

# Iniciar nginx en primer plano
exec nginx -g "daemon off;"