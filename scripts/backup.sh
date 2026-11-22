#!/usr/bin/env bash
# QA-17 · Copia de seguridad en el servidor: base completa (pg_dump formato custom) y archivos subidos.
# Uso: scripts/backup.sh [etiqueta]. Programar a diario con cron (ver docs/despliegue.md).
# Guarda en ./backups (o BACKUP_DIR) y conserva BACKUP_KEEP_DAYS días (14 por defecto). Copia la carpeta
# fuera del servidor (otro proveedor o tu PC): una copia en el mismo disco no protege de perder el servidor.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.prod.yml"
envval() { grep -E "^$1=" .env | tail -1 | cut -d= -f2-; }
DB_USERNAME="$(envval DB_USERNAME)"; DB_DATABASE="$(envval DB_DATABASE)"; BACKUP_DIR="$(envval BACKUP_DIR)"; BACKUP_KEEP_DAYS="$(envval BACKUP_KEEP_DAYS)"
DIR="${BACKUP_DIR:-./backups}"; KEEP="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)${1:+-$1}"
mkdir -p "$DIR"; chmod 700 "$DIR"
$COMPOSE exec -T postgres pg_dump -U "${DB_USERNAME:-gestiahr}" -d "${DB_DATABASE:-gestiahr}" -Fc > "$DIR/db-$STAMP.dump"
# Los archivos se leen del volumen aunque la API esté detenida (contenedor de un solo uso, sin el arranque normal).
$COMPOSE run --rm --no-deps -T --entrypoint tar api -C /app/storage/app -czf - . > "$DIR/files-$STAMP.tar.gz"
sha256sum "$DIR/db-$STAMP.dump" "$DIR/files-$STAMP.tar.gz" > "$DIR/$STAMP.sha256"
find "$DIR" -type f -mtime +"$KEEP" -delete
echo "Copia $STAMP: $(du -h "$DIR/db-$STAMP.dump" | cut -f1) de base y $(du -h "$DIR/files-$STAMP.tar.gz" | cut -f1) de archivos en $DIR"
