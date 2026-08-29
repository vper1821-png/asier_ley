# SecureLab Backend – con agente SecureLab (compilación puro Go, sin CGO)
# Ejecutar desde la raíz del proyecto:
#   docker build -f backend/Dockerfile -t securelab-backend .

# ── Stage 1: Compilar el agente (CGO_ENABLED=0 para todas las plataformas) ──
FROM golang:1.25-alpine AS agent-builder
WORKDIR /src

# Copiar el código del agente (está en backend/securelab-agent/)
COPY securelab-agent/ .

# Sincronizar dependencias y generar vendor (para builds reproducibles)
RUN go mod tidy && go mod vendor

# Compilar binarios Linux (amd64), macOS (amd64/arm64) y Windows (amd64)
# Usamos CGO_ENABLED=0 para que los binarios sean completamente estáticos y portables.
RUN mkdir -p /out && \
    CGO_ENABLED=0 GOOS=linux   GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-linux-x64 ./cmd/agent && \
    CGO_ENABLED=0 GOOS=darwin  GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-mac-x64 ./cmd/agent && \
    CGO_ENABLED=0 GOOS=darwin  GOARCH=arm64 go build -v -ldflags "-s -w" -o /out/securelab-agent-mac-arm64 ./cmd/agent && \
    # 🔽 NUEVO: binario para Windows
    CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -v -ldflags "-s -w" -o /out/securelab-agent-windows-amd64.exe ./cmd/agent

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
    golang-go \
    default-libmysqlclient-dev \
    libfreetype-dev \
    libjpeg62-turbo-dev \
    # NSIS para el instalador Windows
    nsis \
    jq \
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
COPY nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configurar PHP (production)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copiar la aplicación PHP (backend) ANTES de composer install para que vendor se genere correctamente
WORKDIR /var/www/html
COPY . .

# Instalar dependencias PHP (composer)
RUN composer install --no-dev --no-interaction --prefer-source --optimize-autoloader --no-security-blocking --ignore-platform-req=ext-gd --ignore-platform-req=ext-mongodb

# Copiar solo los binarios del agente (no el código fuente completo)
COPY --from=agent-builder /out/ /var/www/html/agent-bin/

# Asegurar permisos de ejecución para los binarios del agente
RUN chmod +x /var/www/html/agent-bin/securelab-agent-*

# 🔽 NUEVO: Preparar los archivos del instalador NSIS
# Crear directorio para el instalador
RUN mkdir -p /var/www/html/installer/output

# Copiar el binario de Windows desde la etapa de compilación
COPY --from=agent-builder /out/securelab-agent-windows-amd64.exe /var/www/html/installer/securelab-agent.exe

# Copiar el script NSIS y el archivo de licencia (deben existir en la raíz del proyecto, dentro de installer/)
COPY installer/SecureLabAgent.nsi /var/www/html/installer/
COPY installer/LICENSE.txt /var/www/html/installer/

# Crear directorios necesarios (logs, uploads, reports, backups, etc.)
RUN mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/reports /var/run/nginx /run/nginx /var/backups/mongodb /var/log \
    && chown -R www-data:www-data /var/www/html

# Copiar backup script y cron
COPY backup.sh /var/www/html/backup.sh
COPY cron-backup /etc/cron.d/mongodb-backup
COPY retention-cleanup.php /var/www/html/retention-cleanup.php
COPY cron-retention /etc/cron.d/retention-cleanup
RUN chmod +x /var/www/html/backup.sh /var/www/html/retention-cleanup.php /etc/cron.d/mongodb-backup /etc/cron.d/retention-cleanup \
    && chmod 644 /etc/cron.d/mongodb-backup /etc/cron.d/retention-cleanup

# Instalar cron y mongodump tools
# Note: mongodb-database-tools not available in Debian Trixie, using mongoclient from php-mongodb instead
RUN apt-get update && apt-get install -y --no-install-recommends cron \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Script de inicio (arranca PHP-FPM, WebSocket, nginx y cron) — AHORA MODIFICADO
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 3838

CMD ["/start.sh"]