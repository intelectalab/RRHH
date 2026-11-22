#!/usr/bin/env bash
# QA-17 · Despliegue en el servidor (VPS con Docker). Uso, desde la carpeta del repositorio:
#   scripts/deploy.sh            # trae main, construye, migra y levanta
#   scripts/deploy.sh <commit>   # despliega una versión concreta (lo usa scripts/rollback.sh)
#   SEED_DEMO=1 scripts/deploy.sh   # además siembra la organización demo (solo en el ambiente de prueba)
set -euo pipefail
cd "$(dirname "$0")/.."

TARGET="${1:-}"
[ -f .env ] || { echo "Falta .env: copia .env.staging.example a .env y complétalo."; exit 1; }
envval() { grep -E "^$1=" .env | tail -1 | cut -d= -f2-; }
DB_USERNAME="$(envval DB_USERNAME)"; DB_DATABASE="$(envval DB_DATABASE)"; BACKUP_DIR="$(envval BACKUP_DIR)"; BACKUP_KEEP_DAYS="$(envval BACKUP_KEEP_DAYS)"
COMPOSE="docker compose -f docker-compose.prod.yml"

echo "==> 1/7 Código"
git fetch --quiet origin
if [ -n "$TARGET" ]; then git checkout --quiet "$TARGET"; else git checkout --quiet main && git pull --quiet --ff-only origin main; fi
export RELEASE="$(git rev-parse --short HEAD)"
echo "    versión ${RELEASE}"

echo "==> 2/7 Copia de seguridad previa"
if $COMPOSE ps --status running postgres 2>/dev/null | grep -q postgres \
  && [ "$($COMPOSE exec -T postgres psql -U "${DB_USERNAME:-gestiahr}" -d "${DB_DATABASE:-gestiahr}" -tAc "select count(*) from tenants" 2>/dev/null | tr -d '[:space:]')" != "0" ] \
  && $COMPOSE exec -T postgres psql -U "${DB_USERNAME:-gestiahr}" -d "${DB_DATABASE:-gestiahr}" -tAc "select 1 from tenants limit 1" >/dev/null 2>&1; then
  scripts/backup.sh pre-deploy
else
  echo "    (sin datos todavía: no hace falta copia)"
fi

echo "==> 3/7 Imágenes"
$COMPOSE build --pull

echo "==> 4/7 Base de datos"
$COMPOSE up -d postgres redis
$COMPOSE run --rm --no-deps api php artisan env:verify
$COMPOSE run --rm api php artisan migrate --force --no-interaction

echo "==> 5/7 Parámetros legales, catálogos y ayuda"
$COMPOSE run --rm api php artisan gestia:legal-parameters:sync
$COMPOSE run --rm api php artisan help:sync-bundled
if [ "${SEED_DEMO:-0}" = "1" ]; then
  [ -n "$(grep -E '^DEMO_ADMIN_PASSWORD=.+' .env || true)" ] || { echo "Define DEMO_ADMIN_PASSWORD en .env para sembrar la demo."; exit 1; }
  $COMPOSE run --rm api php artisan db:seed --force
fi

echo "==> 6/7 Servicios"
$COMPOSE up -d --remove-orphans
# Las colas toman el código nuevo al reiniciar (queue:restart avisa a los trabajadores en curso).
$COMPOSE exec -T api php artisan queue:restart || true

echo "==> 7/7 Salud"
for i in $(seq 1 40); do
  if $COMPOSE exec -T api wget -qO- http://127.0.0.1:8000/up >/dev/null 2>&1; then echo "    API en línea"; break; fi
  [ "$i" = 40 ] && { echo "La API no respondió; revisa: $COMPOSE logs api"; exit 1; }
  sleep 3
done
docker image prune -f >/dev/null
echo "==> Despliegue ${RELEASE} completado."
