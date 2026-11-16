#!/bin/bash
# deploy.sh — Despliegue de Didi2 B2B
set -euo pipefail

APP_DIR="/salida/didi2"
BUILD_DIR="/salida/didi2/dist"
BACKUP_DIR="/salida/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "=== Didi2 B2B Deploy ==="
echo "Timestamp: $TIMESTAMP"

# 1. Backup de la base de datos
echo "[1/5] Creando backup de la base de datos..."
mkdir -p "$BACKUP_DIR"
mysqldump -u didi2_app -p"$DB_PASS" --socket=/tmp/mariadb.sock didi2 > "$BACKUP_DIR/didi2_$TIMESTAMP.sql"
echo "  Backup guardado en $BACKUP_DIR/didi2_$TIMESTAMP.sql"

# 2. Backup de archivos
echo "[2/5] Creando backup de archivos..."
tar -czf "$BACKUP_DIR/files_$TIMESTAMP.tar.gz" -C /salida/didi2 \
    app/ database/ public/ \
    --exclude='storage/cache/*' --exclude='storage/rate/*' \
    --exclude='.phpunit.cache'
echo "  Backup guardado en $BACKUP_DIR/files_$TIMESTAMP.tar.gz"

# 3. Instalar dependencias
echo "[3/5] Instalando dependencias..."
cd "$APP_DIR"
composer install --no-dev --optimize-autoload 2>/dev/null || true

# 4. Ejecutar migraciones
echo "[4/5] Ejecutando migraciones..."
export PATH=/salida/tools/phpenv/bin:$PATH
php bin/migrate.php

# 5. Verificar salud
echo "[5/5] Verificando salud..."
curl -sf http://127.0.0.1:8000/health > /dev/null && echo "  Health check: OK" || echo "  Health check: FALLÓ"

echo ""
echo "=== Deploy completado ==="
echo "Backup: $BACKUP_DIR/didi2_$TIMESTAMP.sql"
