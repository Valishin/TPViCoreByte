# syntax=docker/dockerfile:1.7
#
# Imagen de producción del TPV (mini PC Debian 13, linux/amd64). Base Debian 13 (trixie).
#
#   docker buildx build --platform linux/amd64 -f docker/production/Dockerfile \
#     --build-arg APP_VERSION=$(cat VERSION) --build-arg APP_COMMIT=$(git rev-parse --short HEAD) \
#     -t ghcr.io/<usuario>/tpv-hosteleria:$(cat VERSION) .
#
# (o scripts/build-image.sh). Una sola imagen para todos los servicios: app
# (FrankenPHP en :80), reverb, colas y scheduler; cambia solo el comando.

ARG PHP_VERSION=8.4

# --- 1. Assets (Vite). Son iguales en cualquier arquitectura: se compilan en la nativa del Mac.
FROM --platform=$BUILDPLATFORM node:22-trixie-slim AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
# Sin VITE_REVERB_*: en producción Echo usa el host y el puerto de la página y la
# clave de Reverb que la página trae en <meta> (cada instalación tiene la suya).
RUN npm run build

# --- 2. Dependencias PHP sin las de desarrollo (código PHP: también en la arquitectura nativa).
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- 3. Imagen final: FrankenPHP (Caddy + PHP 8.4) con opcache.
FROM dunglas/frankenphp:1-php${PHP_VERSION}-trixie AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:80 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

# Extensiones PHP de la app (PostgreSQL, colas con pcntl, Filament, dompdf, logo del ticket).
RUN install-php-extensions pdo_pgsql pcntl intl zip gd bcmath opcache sockets

# Herramientas: cliente de PostgreSQL 18 (pg_dump de las copias), age (cifrado),
# rclone (subida remota), procps (healthcheck de los workers), curl.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg procps age rclone; \
    install -d /usr/share/keyrings; \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt trixie-pgdg main" > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends postgresql-client-18; \
    apt-get purge -y gnupg; apt-get autoremove -y; rm -rf /var/lib/apt/lists/*

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-tpv.ini
COPY docker/production/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/production/entrypoint.sh /usr/local/bin/tpv-entrypoint

WORKDIR /app
COPY --from=vendor /src /app
COPY --from=assets /src/public/build /app/public/build

# Usuario sin privilegios; FrankenPHP puede escuchar en :80 sin ser root.
RUN set -eux; \
    useradd --uid 1000 --create-home --shell /usr/sbin/nologin tpv; \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
    mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache /backups /data/caddy /config/caddy; \
    php artisan package:discover --ansi; \
    php artisan filament:assets; \
    ln -s /app/storage/app/public /app/public/storage; \
    chown -R tpv:tpv /app/storage /app/bootstrap/cache /backups /data /config; \
    chmod +x /usr/local/bin/tpv-entrypoint

# Versión y commit AL FINAL: cambian en cada build y no deben invalidar las capas de arriba.
ARG APP_VERSION=dev
ARG APP_COMMIT=
ENV APP_VERSION=${APP_VERSION} \
    APP_COMMIT=${APP_COMMIT}
LABEL org.opencontainers.image.title="TPV Hostelería" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${APP_COMMIT}"

USER tpv
EXPOSE 80
ENTRYPOINT ["tpv-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
