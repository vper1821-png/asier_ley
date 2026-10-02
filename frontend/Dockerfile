# Frontend PHP - SecureLab2v
# PHP-FPM + nginx

FROM php:8.3-fpm

# Instalar dependencias
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    curl \
    libcurl4-openssl-dev \
    libpng-dev \
    libonig-dev \
    && docker-php-ext-install curl mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configurar nginx
COPY nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configurar PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# 🔒 Hardening PHP: ocultar versión y endurecer sesiones
RUN printf 'expose_php = Off\n\
display_errors = Off\n\
log_errors = On\n\
log_errors_max_len = 1024\n\
session.cookie_httponly = On\n\
session.cookie_secure = On\n\
session.cookie_samesite = Strict\n\
session.use_strict_mode = On\n\
session.use_only_cookies = On\n\
session.gc_maxlifetime = 3600\n' \
    > /usr/local/etc/php/conf.d/zz-hardening.ini

# Copiar la aplicacion
WORKDIR /var/www/html
COPY . .

# Copiar logo desde el frontend React
COPY logo-nuevo.png /var/www/html/logo-nuevo.png

# Crear directorios necesarios
RUN mkdir -p /var/www/html/uploads /var/run/nginx /run/nginx \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

# Entrypoint: arrancar PHP-FPM y nginx
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

CMD ["/entrypoint.sh"]
