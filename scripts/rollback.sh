#!/usr/bin/env bash
set -euo pipefail

TARGET_VERSION="${1:-HEAD~1}"
echo "==> Iniciando procedimiento de reversión hacia versión: ${TARGET_VERSION}"

# 1. Detener despachadores en caso de emergencia si se requiere
php apps/api/artisan down --render="errors.503" --secret="ayni-maintenance-bypass" || true

# 2. Revertir código al commit o tag objetivo
git checkout "${TARGET_VERSION}"

# 3. Instalar dependencias exactas de la versión previa
(cd apps/api && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction)

# 4. Reiniciar trabajadores de colas
php apps/api/artisan horizon:terminate || true
php apps/api/artisan queue:restart

# 5. Levantar aplicación tras validar salud
php apps/api/artisan up

echo "==> Reversión completada con éxito."

