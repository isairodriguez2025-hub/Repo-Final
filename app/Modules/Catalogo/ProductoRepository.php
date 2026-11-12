<?php

declare(strict_types=1);

namespace App\Modules\Catalogo;

use App\Modules\Auth\UsuarioRepository;
use PDO;

final class ProductoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function categorias(): array
    {
        return $this->pdo->query('SELECT * FROM categorias WHERE activa = 1 ORDER BY nombre')->fetchAll() ?: [];
    }

    public function categoriaPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categorias WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function listarPorMayorista(int $mayoristaId, ?string $busqueda = null): array
    {
        $sql = 'SELECT p.*, c.nombre AS categoria
                FROM productos p
                JOIN categorias c ON c.id = p.categoria_id
                WHERE p.mayorista_id = ? AND p.deleted_at IS NULL';
        $params = [$mayoristaId];

        if ($busqueda !== null && trim($busqueda) !== '') {
            $sql .= ' AND (p.nombre LIKE ? OR p.descripcion LIKE ?)';
            $params[] = '%' . trim($busqueda) . '%';
            $params[] = '%' . trim($busqueda) . '%';
        }

        $sql .= ' ORDER BY p.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function buscarPorUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM productos WHERE uuid = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$uuid]);

        return $stmt->fetch() ?: null;
    }

    public function crear(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO productos
             (uuid, mayorista_id, categoria_id, nombre, descripcion, precio_base, iva_tasa,
              unidad_venta, contenido, peso_kg, volumen_m3, cantidad_minima, multiplo_venta,
              perecedero, requiere_frio, inflamable, restringido, stock_disponible, activo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            UsuarioRepository::uuid4(),
            $d['mayorista_id'],
            $d['categoria_id'],
            $d['nombre'],
            $d['descripcion'] ?? null,
            $d['precio_base'],
            $d['iva_tasa'] ?? 16.00,
            $d['unidad_venta'],
            $d['contenido'],
            $d['peso_kg'],
            $d['volumen_m3'],
            $d['cantidad_minima'] ?? 1,
            $d['multiplo_venta'] ?? 1,
            !empty($d['perecedero']) ? 1 : 0,
            !empty($d['requiere_frio']) ? 1 : 0,
            !empty($d['inflamable']) ? 1 : 0,
            !empty($d['restringido']) ? 1 : 0,
            0, // el stock entra como movimiento, no en el alta
            !empty($d['activo']) ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE productos SET
                categoria_id = ?, nombre = ?, descripcion = ?, precio_base = ?, iva_tasa = ?,
                unidad_venta = ?, contenido = ?, peso_kg = ?, volumen_m3 = ?,
                cantidad_minima = ?, multiplo_venta = ?, perecedero = ?, requiere_frio = ?,
                inflamable = ?, restringido = ?, activo = ?
             WHERE id = ?'
        );

        $stmt->execute([
            $d['categoria_id'], $d['nombre'], $d['descripcion'] ?? null, $d['precio_base'],
            $d['iva_tasa'] ?? 16.00, $d['unidad_venta'], $d['contenido'], $d['peso_kg'],
            $d['volumen_m3'], $d['cantidad_minima'] ?? 1, $d['multiplo_venta'] ?? 1,
            !empty($d['perecedero']) ? 1 : 0, !empty($d['requiere_frio']) ? 1 : 0,
            !empty($d['inflamable']) ? 1 : 0, !empty($d['restringido']) ? 1 : 0,
            !empty($d['activo']) ? 1 : 0, $id,
        ]);
    }

    public function desactivar(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE productos SET activo = 0, deleted_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function agregarImagen(int $productoId, string $ruta, int $orden): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO producto_imagenes (producto_id, ruta, orden) VALUES (?,?,?)');
        $stmt->execute([$productoId, $ruta, $orden]);
    }

    public function imagenes(int $productoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM producto_imagenes WHERE producto_id = ? ORDER BY orden');
        $stmt->execute([$productoId]);

        return $stmt->fetchAll() ?: [];
    }

    public function contarImagenes(int $productoId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM producto_imagenes WHERE producto_id = ?');
        $stmt->execute([$productoId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ajusta el stock y deja el movimiento registrado, todo en una transacción.
     *
     * El UPDATE lleva la condición `stock_disponible + ? >= 0`: si el resultado
     * fuera negativo, no actualiza ninguna fila y se revierte. Así dos ventas
     * simultáneas del último producto no pueden dejarlo en -1.
     */
    public function ajustarStock(int $productoId, int $delta, string $tipo, ?int $usuarioId, ?string $motivo = null): bool
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT stock_disponible FROM productos WHERE id = ? FOR UPDATE');
            $stmt->execute([$productoId]);
            $previo = $stmt->fetchColumn();

            if ($previo === false) {
                $this->pdo->rollBack();

                return false;
            }

            $previo = (int) $previo;
            $nuevo = $previo + $delta;

            if ($nuevo < 0) {
                $this->pdo->rollBack();

                return false;
            }

            $stmt = $this->pdo->prepare('UPDATE productos SET stock_disponible = ? WHERE id = ?');
            $stmt->execute([$nuevo, $productoId]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO inventario_movimientos
                 (producto_id, tipo, cantidad, cantidad_previa, cantidad_nueva, usuario_id, motivo)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$productoId, $tipo, $delta, $previo, $nuevo, $usuarioId, $motivo]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** Escalas de descuento del producto, de mayor a menor cantidad. */
    public function escalas(int $productoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM escala_descuentos WHERE producto_id = ? ORDER BY cantidad_minima DESC'
        );
        $stmt->execute([$productoId]);

        return $stmt->fetchAll() ?: [];
    }

    /** Reemplaza el juego completo de escalas de un producto. */
    public function reemplazarEscalas(int $productoId, array $escalas): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM escala_descuentos WHERE producto_id = ?')->execute([$productoId]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO escala_descuentos (producto_id, cantidad_minima, tipo, valor) VALUES (?,?,?,?)'
            );

            foreach ($escalas as $e) {
                $stmt->execute([$productoId, (int) $e['cantidad_minima'], (string) $e['tipo'], (float) $e['valor']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function movimientos(int $productoId, int $limite = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inventario_movimientos WHERE producto_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $productoId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
