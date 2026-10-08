# Serveur web interne (§14.2) : fichiers de la PWA et relais de l'API vers PHP-FPM.

FROM node:22-bookworm-slim AS build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM nginx:1.27-alpine
COPY docker/web/default.conf /etc/nginx/conf.d/default.conf
COPY docker/web/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY docker/web/php.conf /etc/nginx/snippets/php.conf
COPY --from=build /app/dist /usr/share/nginx/html
