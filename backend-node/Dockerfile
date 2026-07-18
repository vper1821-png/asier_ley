# Etapa 1: Instalar herramientas (Go, Wine)
FROM node:20-slim AS builder
RUN apt-get update && apt-get install -y \
    curl gnupg build-essential git wget software-properties-common \
    && dpkg --add-architecture i386 \
    && apt-get update \
    && apt-get install -y wine64 wine32 \
    && rm -rf /var/lib/apt/lists/*
RUN wget https://go.dev/dl/go1.23.0.linux-amd64.tar.gz \
    && tar -C /usr/local -xzf go1.23.0.linux-amd64.tar.gz \
    && rm go1.23.0.linux-amd64.tar.gz
ENV PATH="/usr/local/go/bin:${PATH}"

# Etapa 2: Aplicación Node.js
FROM node:20-slim
# Copiar herramientas desde builder
COPY --from=builder /usr/local/go /usr/local/go
COPY --from=builder /usr/bin/wine /usr/bin/wine
COPY --from=builder /usr/bin/wine64 /usr/bin/wine64
COPY --from=builder /usr/bin/wine32 /usr/bin/wine32
COPY --from=builder /usr/lib/wine /usr/lib/wine
COPY --from=builder /lib/i386-linux-gnu /lib/i386-linux-gnu
COPY --from=builder /usr/lib/i386-linux-gnu /usr/lib/i386-linux-gnu
COPY --from=builder /lib/x86_64-linux-gnu /lib/x86_64-linux-gnu
COPY --from=builder /usr/lib/x86_64-linux-gnu /usr/lib/x86_64-linux-gnu
ENV PATH="/usr/local/go/bin:${PATH}"

WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
COPY agent-go /app/agent-go

EXPOSE 3838
CMD ["node", "server.js"]
