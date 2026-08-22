# SecureLab Backend – con agente SecureLab (compilación puro Go, sin CGO)
# Ejecutar desde la raíz del proyecto:
#   docker build -f backend/Dockerfile -t securelab-backend .

# ── Stage 1: Compilar el agente (CGO_ENABLED=0 para todas las plataformas) ──
FROM golang:1.25-alpine AS agent-builder
WORKDIR /src

# Copiar el código del agente (está en backend/securelab-agent/)
COPY backend/securelab-agent/ .

# Sincronizar dependencias y generar vendor (para builds reproducibles)
RUN go mod tidy && go mod vendor

# Compilar binarios Linux (amd64) y macOS (amd64/arm64) — CGO_ENABLED=0, sin tags windows
# Usamos CGO_ENABLED=0 para que los binarios sean completamente estáticos y portables.
RUN mkdir -p /out && \
    CGO_ENABLED=0 GOOS=linux   GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-linux-x64 ./cmd/agent && \
    CGO_ENABLED=0 GOOS=darwin  GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-mac-x64 ./cmd/agent && \
    CGO_ENABLED=0 GOOS=darwin  GOARCH=arm64 go build -v -ldflags "-s -w" -o /out/securelab-agent-mac-arm64 ./cmd/agent

# ── Stage 2: Runtime PHP con nginx ──
FROM php:8.3-fpm

# Instalar dependencias del sistema (nginx, utilidades, extensiones PHP)
# Nota: SQLCipher ya no es necesario, porque el agente usa modernc.org/sqlite (puro Go)
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

# Instalar extensión MongoDB para PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf gcc g++ make libssl-dev \
    && pecl channel-update pecl.php.net \
    && pecl install mongodb-1.20.1 \
    && docker-php-ext-enable mongodb \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar nginx (está en backend/nginx.conf)
COPY backend/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configurar PHP (production)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Instalar dependencias PHP (composer)
WORKDIR /var/www/html
COPY backend/composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-source --optimize-autoloader --no-security-blocking --ignore-platform-req=ext-gd

# Copiar el resto de la aplicación PHP (backend)
COPY backend/ .

# Copiar solo los binarios del agente (no el código fuente completo)
COPY --from=agent-builder /out/ /var/www/html/agent-bin/

# Asegurar permisos de ejecución para los binarios del agente
RUN chmod +x /var/www/html/agent-bin/securelab-agent-*

# Crear directorios necesarios (logs, uploads, reports, etc.)
RUN mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/reports /var/run/nginx /run/nginx \
    && chown -R www-data:www-data /var/www/html

# Script de inicio (arranca PHP-FPM, WebSocket y nginx)
COPY backend/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 3838

CMD ["/start.sh"]