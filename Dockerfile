FROM node:20-slim AS builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build

FROM node:20-slim
WORKDIR /app
COPY --from=builder /app/dist ./dist
RUN npm install -g vite
EXPOSE 4173
CMD ["npx", "vite", "preview", "--port", "4173", "--host"]
