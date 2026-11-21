#!/usr/bin/env bash
# QA-17 · Volver a una versión anterior en el servidor. Uso: scripts/rollback.sh <commit o tag> (por defecto HEAD~1).
# Las migraciones se escriben compatibles hacia atrás (expandir → migrar), así que la versión anterior funciona
# sobre la base actual. Si hace falta volver también los datos: scripts/restore.sh <copia> (ver docs/despliegue.md).
set -euo pipefail
cd "$(dirname "$0")/.."
TARGET_VERSION="${1:-HEAD~1}"
TARGET_VERSION="$(git rev-parse --short "$TARGET_VERSION")"
echo "==> Reversión a ${TARGET_VERSION}"
git checkout --quiet "${TARGET_VERSION}"
scripts/deploy.sh "${TARGET_VERSION}"
docker compose -f docker-compose.prod.yml exec -T api php artisan queue:restart || true
echo "==> Reversión completada. Para volver a la última versión: scripts/deploy.sh"
