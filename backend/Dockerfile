# Backend PHP - SecureLab2v
# PHP-FPM + nginx + MongoDB driver + Go + wixl

# ── Stage 1: compilar el agente Go para todas las plataformas ──
FROM golang:1.25-alpine AS agent-builder
WORKDIR /src
COPY agent-go/go.mod agent-go/go.sum ./
RUN go mod download
COPY agent-go/ .
RUN mkdir -p /out && \
    CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags "-s -w" -o /out/agent-win-x64.exe . && \
    CGO_ENABLED=0 GOOS=linux   GOARCH=amd64 go build -ldflags "-s -w" -o /out/agent-linux-x64 . && \
    CGO_ENABLED=0 GOOS=darwin  GOARCH=amd64 go build -ldflags "-s -w" -o /out/agent-mac-x64 . && \
    CGO_ENABLED=0 GOOS=darwin  GOARCH=arm64 go build -ldflags "-s -w" -o /out/agent-mac-arm64 .

# ── Stage 2: runtime PHP ──
FROM php:8.3-fpm

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    curl \
    libcurl4-openssl-dev \
    libpng-dev \
    libonig-dev \
    libzip-dev \
    unzip \
    git \
    wget \
    msitools \
    wixl \
    golang-go \
    default-libmysqlclient-dev \
    && docker-php-ext-install curl mbstring zip pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instalar MongoDB PHP extension
RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf gcc g++ make libssl-dev \
    && pecl channel-update pecl.php.net \
    && pecl install mongodb-1.20.1 \
    && docker-php-ext-enable mongodb \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar nginx
COPY backend-php/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configurar PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Instalar dependencias PHP (composer) con cache de capa
WORKDIR /var/www/html
COPY backend-php/composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-security-blocking

# Copiar la aplicacion
COPY backend-php/ .

# Copiar el codigo del agente Go y los binarios pre-compilados
COPY agent-go/ /var/www/html/agent-go/
COPY --from=agent-builder /out/ /var/www/html/agent-bin/

# Crear directorios necesarios
RUN mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/reports /var/run/nginx /run/nginx \
    && chown -R www-data:www-data /var/www/html

EXPOSE 3838

# Entrypoint
COPY backend-php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

CMD ["/entrypoint.sh"]
