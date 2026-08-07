#!/bin/sh
set -e

# Create required directories
mkdir -p /var/run/nginx /run/nginx
chown -R www-data:www-data /var/www/html

# Start PHP-FPM in background
php-fpm -D

# Start nginx in foreground
exec nginx -g "daemon off;"
