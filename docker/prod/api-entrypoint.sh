#!/bin/sh
# QA-17 · Al arrancar: verifica variables críticas y arma las cachés de configuración, rutas y vistas.
set -e
cd /app
php artisan env:verify
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
exec "$@"
