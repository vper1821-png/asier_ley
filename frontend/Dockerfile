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

# Copiar la aplicacion
WORKDIR /var/www/html
COPY . .

# Copiar logo desde el frontend React
COPY frontend-react/logo-nuevo.png /var/www/html/logo-nuevo.png

# Crear directorios necesarios
RUN mkdir -p /var/www/html/uploads /var/run/nginx /run/nginx \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

# Entrypoint: arrancar PHP-FPM y nginx
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

CMD ["/entrypoint.sh"]
