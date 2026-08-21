#!/bin/sh
set -e

# Create required directories
mkdir -p /var/run/nginx /run/nginx
chown -R www-data:www-data /var/www/html

# Start PHP-FPM in background
php-fpm -D

# Start WebSocket server in background
php /var/www/html/ws-server.php > /var/log/ws-server.log 2>&1 &

# Start nginx in foreground
exec nginx -g "daemon off;"
