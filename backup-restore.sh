#!/bin/bash
# backup-restore.sh — Respaldo y restauración de Didi2 B2B
set -euo pipefail

BACKUP_DIR="/salida/backups"
DB_NAME="didi2"
DB_USER="didi2_app"
DB_PASS="${DB_PASS:-}"
SOCKET="/tmp/mariadb.sock"

usage() {
    echo "Uso:"
    echo "  $0 backup          Crear respaldo de BD y archivos"
    echo "  $0 restore ARCHIVO Restaurar BD desde respaldo SQL"
    echo "  $0 listar          Listar respaldos disponibles"
}

do_backup() {
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    mkdir -p "$BACKUP_DIR"

    echo "Creando backup de la base de datos..."
    mysqldump -u "$DB_USER" -p"$DB_PASS" --socket="$SOCKET" "$DB_NAME" \
        > "$BACKUP_DIR/didi2_$TIMESTAMP.sql"
    echo "  → $BACKUP_DIR/didi2_$TIMESTAMP.sql"

    echo "Creando backup de archivos..."
    tar -czf "$BACKUP_DIR/files_$TIMESTAMP.tar.gz" \
        -C /salida/didi2 app/ database/ public/ \
        --exclude='storage/cache/*' --exclude='storage/rate/*' \
        --exclude='.phpunit.cache'
    echo "  → $BACKUP_DIR/files_$TIMESTAMP.tar.gz"
}

do_restore() {
    local archivo="$1"
    if [[ ! -f "$archivo" ]]; then
        echo "Error: archivo no encontrado: $archivo"
        exit 1
    fi

    echo "Restaurando base de datos desde $archivo..."
    mysql -u "$DB_USER" -p"$DB_PASS" --socket="$SOCKET" "$DB_NAME" < "$archivo"
    echo "  Restauración completada."
}

do_list() {
    echo "Respaldo de BD:"
    ls -la "$BACKUP_DIR"/didi2_*.sql 2>/dev/null || echo "  Sin respaldos de BD"
    echo "Respaldo de archivos:"
    ls -la "$BACKUP_DIR"/files_*.tar.gz 2>/dev/null || echo "  Sin respaldos de archivos"
}

case "${1:-}" in
    backup)  do_backup ;;
    restore) do_restore "${2:-}" ;;
    listar)  do_list ;;
    *)       usage; exit 1 ;;
esac
