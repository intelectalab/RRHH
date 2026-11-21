#!/usr/bin/env bash
# QA-17 · Restauración. Dos modos:
#   scripts/restore.sh --check <STAMP>   prueba la copia en una base aparte (no toca la base en uso) y la borra
#   scripts/restore.sh <STAMP>           restaura la base y los archivos de esa copia (detiene la app mientras tanto)
# STAMP es la parte común del nombre de los archivos en ./backups (p. ej. 20261121T030000Z).
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.prod.yml"
set -a; . ./.env; set +a
DIR="${BACKUP_DIR:-./backups}"; U="${DB_USERNAME:-gestiahr}"; D="${DB_DATABASE:-gestiahr}"
CHECK=0; [ "${1:-}" = "--check" ] && { CHECK=1; shift; }
STAMP="${1:?Indica la copia (STAMP)}"
DUMP="$DIR/db-$STAMP.dump"; FILES="$DIR/files-$STAMP.tar.gz"
[ -f "$DUMP" ] || { echo "No existe $DUMP"; exit 1; }
[ -f "$DIR/$STAMP.sha256" ] && sha256sum -c --quiet "$DIR/$STAMP.sha256"

if [ "$CHECK" = 1 ]; then
  T="restore_check_$(date +%s)"
  $COMPOSE exec -T postgres createdb -U "$U" "$T"
  $COMPOSE exec -T postgres pg_restore -U "$U" -d "$T" --no-owner --exit-on-error < "$DUMP"
  N=$($COMPOSE exec -T postgres psql -U "$U" -d "$T" -tAc "select count(*) from tenants")
  M=$($COMPOSE exec -T postgres psql -U "$U" -d "$T" -tAc "select count(*) from migrations")
  $COMPOSE exec -T postgres dropdb -U "$U" "$T"
  tar -tzf "$FILES" >/dev/null
  echo "Prueba de restauración OK: $N organizaciones, $M migraciones, archivos legibles. La base en uso no se tocó."
  exit 0
fi

read -r -p "Esto reemplaza la base y los archivos actuales por la copia $STAMP. Escribe RESTAURAR para seguir: " OK
[ "$OK" = "RESTAURAR" ] || { echo "Cancelado."; exit 1; }
scripts/backup.sh antes-de-restaurar
$COMPOSE stop caddy web site horizon scheduler api
$COMPOSE exec -T postgres psql -U "$U" -d postgres -c "select pg_terminate_backend(pid) from pg_stat_activity where datname='$D' and pid<>pg_backend_pid()" >/dev/null
$COMPOSE exec -T postgres dropdb -U "$U" "$D"
$COMPOSE exec -T postgres createdb -U "$U" "$D"
$COMPOSE exec -T postgres pg_restore -U "$U" -d "$D" --exit-on-error < "$DUMP"
$COMPOSE run --rm --no-deps -T api sh -c "rm -rf /app/storage/app/* && tar -C /app/storage/app -xzf -" < "$FILES"
$COMPOSE up -d
echo "Restauración de $STAMP completada."
