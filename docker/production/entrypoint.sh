#!/bin/sh
# Arranque de cualquier servicio del TPV: cachés de Laravel (con el .env de
# esta instalación) y después el comando del servicio.
set -e

cd /app

# Las cachés dependen del entorno: se generan al arrancar, no al construir.
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null

exec "$@"
