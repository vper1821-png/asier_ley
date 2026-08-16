# SecureLab Backend - con agente SecureLab (SQLCipher estático)
# Ejecutar desde la raíz del proyecto:
#   docker build -f backend/Dockerfile -t securelab-backend .

# ── Stage 1: Compilar agente con SQLCipher estático ──
FROM golang:1.25-alpine AS agent-builder
WORKDIR /src

# 1. Herramientas de compilación
RUN apk add --no-cache \
    gcc \
    musl-dev \
    make \
    autoconf \
    automake \
    libtool \
    git

# 2. Compilar SQLCipher desde fuente (estático)
RUN git clone --depth 1 https://github.com/sqlcipher/sqlcipher.git /tmp/sqlcipher \
    && cd /tmp/sqlcipher \
    && ./configure --enable-static --disable-shared --disable-tcl \
    && make -j$(nproc) && make install \
    && cp /usr/local/lib/libsqlcipher.a /usr/lib/ \
    && rm -rf /tmp/sqlcipher

# 3. Variables de entorno para CGO (apuntan a SQLCipher estático)
ENV CGO_CFLAGS="-I/usr/local/include"
ENV CGO_LDFLAGS="-L/usr/local/lib -lsqlcipher -lcrypto -lssl -lm -lz"

# 4. Copiar el código del agente (desde el contexto de build)
COPY securelab-agent/ .

# 5. Descargar dependencias
RUN go mod tidy

# 6. Compilar binarios estáticos para todas las plataformas
RUN mkdir -p /out && \
    CGO_ENABLED=1 GOOS=windows GOARCH=amd64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-win-x64.exe . && \
    CGO_ENABLED=1 GOOS=linux   GOARCH=amd64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-linux-x64 . && \
    CGO_ENABLED=1 GOOS=darwin  GOARCH=amd64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-mac-x64 . && \
    CGO_ENABLED=1 GOOS=darwin  GOARCH=arm64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-mac-arm64 .

# ── Stage 2: Runtime PHP ──
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
    libfreetype-dev \
    libjpeg62-turbo-dev \
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

# Copiar los binarios del agente (compilados estáticamente)
COPY --from=agent-builder /out/ /var/www/html/agent-bin/

# Asegurar permisos de ejecución
RUN chmod +x /var/www/html/agent-bin/securelab-agent-*

# Crear directorios necesarios
RUN mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/reports /var/run/nginx /run/nginx \
    && chown -R www-data:www-data /var/www/html

# Script de inicio
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 3838

CMD ["/start.sh"]