# Backend PHP - SecureLab2v
# PHP-FPM + nginx + MongoDB driver + Go + wixl
# AHORA CON EL NUEVO AGENTE securelab-agent (CGO_ENABLED=1 para SQLCipher)

# ── Stage 1: compilar el NUEVO agente Go (securelab-agent) ──
FROM golang:1.25-alpine AS agent-builder
WORKDIR /src

# Instalar dependencias para CGO y SQLCipher (necesario para el cifrado de la base de datos)
RUN apk add --no-cache \
    gcc \
    musl-dev \
    sqlcipher-dev \
    pkgconfig

# Copiar el código del nuevo agente (carpeta securelab-agent)
COPY securelab-agent/ .

# Asegurar que las dependencias están correctas
RUN go mod tidy

# Compilar con CGO_ENABLED=1 (obligatorio para SQLCipher) y enlace estático
RUN mkdir -p /out && \
    CGO_ENABLED=1 GOOS=windows GOARCH=amd64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-win-x64.exe . && \
    CGO_ENABLED=1 GOOS=linux   GOARCH=amd64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-linux-x64 . && \
    CGO_ENABLED=1 GOOS=darwin  GOARCH=amd64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-mac-x64 . && \
    CGO_ENABLED=1 GOOS=darwin  GOARCH=arm64 go build -v -ldflags "-s -w -extldflags '-static'" -o /out/securelab-agent-mac-arm64 .

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

# Instalar dependencias PHP (composer) con cache de capa
WORKDIR /var/www/html
COPY composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-security-blocking --ignore-platform-req=ext-gd
# Copiar la aplicacion
COPY . .

# Copiar el codigo del agente Go y los binarios pre-compilados
COPY securelab-agent/ /var/www/html/securelab-agent/   # Cambio de nombre de carpeta
COPY --from=agent-builder /out/ /var/www/html/agent-bin/

# Asegurar permisos de ejecución en los binarios del agente
RUN chmod +x /var/www/html/agent-bin/securelab-agent-*

# Crear directorios necesarios
RUN mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/reports /var/run/nginx /run/nginx \
    && chown -R www-data:www-data /var/www/html

EXPOSE 3838

# Entrypoint multi-proceso
COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]