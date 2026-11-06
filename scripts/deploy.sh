#!/usr/bin/env bash
set -euo pipefail

ENVIRONMENT="${1:-staging}"
echo "==> Iniciando despliegue automatizado para el entorno: ${ENVIRONMENT}"

# 1. Verificar variables obligatorias y secretos
php apps/api/artisan env:verify --environment="${ENVIRONMENT}"

# 2. Migraciones sin corte (expandir -> migrar)
echo "==> Ejecutando migraciones de base de datos..."
php apps/api/artisan migrate --force --no-interaction

# 3. Reiniciar colas y Horizon suavemente
echo "==> Reiniciando colas y Horizon..."
php apps/api/artisan horizon:terminate || true
php apps/api/artisan queue:restart

# 4. Limpiar y optimizar cachés
echo "==> Optimizando cachés de configuración y rutas..."
php apps/api/artisan config:cache
php apps/api/artisan route:cache

echo "==> Despliegue completado con éxito."

