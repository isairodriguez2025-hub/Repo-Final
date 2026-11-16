#!/bin/bash
# analyze-queries.sh — EXPLAIN para queries críticas de Didi2 B2B
set -euo pipefail

export PATH=/salida/tools/phpenv/bin:$PATH

DB="didi2"
USER="didi2_app"
PASS="${DB_PASS:-}"
SOCKET="/tmp/mariadb.sock"

queries=(
    "SELECT * FROM productos WHERE uuid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' LIMIT 1"
    "SELECT * FROM carrito_items WHERE carrito_id = 1"
    "SELECT * FROM detalle_pedido WHERE pedido_id = 1"
    "SELECT p.*, c.nombre_comercial FROM pedidos p JOIN mayoristas_perfil c ON c.id = p.mayorista_id WHERE p.estado = 'borrador'"
    "SELECT COUNT(*) FROM pedidos WHERE DATE(created_at) = CURDATE()"
    "SELECT * FROM usuarios WHERE email = 'test@example.com' LIMIT 1"
    "SELECT * FROM productos WHERE nombre_producto LIKE '%busqueda%' LIMIT 20"
    "SELECT * FROM mayoristas_perfil WHERE estado_kyc = 'pendiente'"
)

echo "=== EXPLAIN Analysis for Didi2 B2B ==="
echo ""

for i in "${!queries[@]}"; do
    q="${queries[$i]}"
    echo "--- Query $((i+1)) ---"
    echo "SQL: $q"
    echo "EXPLAIN:"
    mysql -u "$USER" -p"$PASS" --socket="$SOCKET" "$DB" \
        -e "EXPLAIN $q" 2>/dev/null || echo "  (error al ejecutar EXPLAIN)"
    echo ""
done
