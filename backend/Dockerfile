# SecureLab Backend – con agente SecureLab (SQLCipher + mattn/go-sqlite3)
# Ejecutar desde la raíz del proyecto:
#   docker build -f backend/Dockerfile -t securelab-backend .

# ── Stage 1: Compilar agente (enlace dinámico con SQLCipher) ──
FROM golang:1.25-alpine AS agent-builder
WORKDIR /src

# 1. Instalar dependencias para CGO y SQLCipher
RUN apk add --no-cache \
    gcc \
    musl-dev \
    sqlcipher-dev \
    pkgconfig

# 2. Copiar el código del agente (desde la raíz del proyecto)....
COPY securelab-agent/ .

# 3. Sincronizar dependencias (usando el go.mod actualizado)
#    Esto descargará todas las dependencias con las versiones actualizadas
RUN go mod tidy && go mod vendor

# 4. Compilar binarios para todas las plataformas (enlace dinámico)
RUN mkdir -p /out && \
    CGO_ENABLED=1 GOOS=windows GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-win-x64.exe ./cmd/agent && \
    CGO_ENABLED=1 GOOS=linux   GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-linux-x64 ./cmd/agent && \
    CGO_ENABLED=1 GOOS=darwin  GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-mac-x64 ./cmd/agent && \
    CGO_ENABLED=1 GOOS=darwin  GOARCH=arm64 go build -v -ldflags "-s -w" -o /out/securelab-agent-mac-arm64 ./cmd/agent

# ── Stage 2: Runtime PHP ──
FROM php:8.3-fpm

# Instalar dependencias del sistema (incluyendo SQLCipher para el runtime)
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
    libfreetype-dev \
    libjpeg62-turbo-dev \
    sqlcipher \                  
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install curl mbstring zip pdo_mysql gd \
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
COPY nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configurar PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Instalar dependencias PHP (composer)
WORKDIR /var/www/html
COPY composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-security-blocking --ignore-platform-req=ext-gd

# Copiar la aplicación PHP
COPY . .

# Copiar SOLO los binarios del agente (no el código fuente)
COPY --from=agent-builder /out/ /var/www/html/agent-bin/

# Asegurar permisos de ejecución
RUN chmod +x /var/www/html/agent-bin/securelab-agent-*

# Crear directorios necesarios
RUN mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/reports /var/run/nginx /run/nginx \
    && chown -R www-data:www-data /var/www/html

# Script de inicio (PHP-FPM + nginx + WebSocket)
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 3838

CMD ["/start.sh"]