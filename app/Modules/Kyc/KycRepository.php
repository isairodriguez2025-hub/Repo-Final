<?php

declare(strict_types=1);

namespace App\Modules\Kyc;

use PDO;

final class KycRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function crear(int $usuarioId, string $tipo, string $ruta, string $mime, int $bytes): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO kyc_documentos (usuario_id, tipo, ruta_archivo, mime, tamano_bytes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$usuarioId, $tipo, $ruta, $mime, $bytes]);

        return (int) $this->pdo->lastInsertId();
    }

    public function listarPorUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tipo, estado, motivo_rechazo, created_at, mime, tamano_bytes
             FROM kyc_documentos WHERE usuario_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$usuarioId]);

        return $stmt->fetchAll() ?: [];
    }

    public function listarPendientes(int $limite = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT k.id, k.tipo, k.created_at, u.email, u.id AS usuario_id
             FROM kyc_documentos k
             JOIN usuarios u ON u.id = k.usuario_id
             WHERE k.estado = 'pendiente'
             ORDER BY k.created_at ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM kyc_documentos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function marcarEstado(int $id, string $estado, ?int $adminId, ?string $motivo = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE kyc_documentos SET estado = ?, revisado_por = ?, motivo_rechazo = ? WHERE id = ?'
        );
        $stmt->execute([$estado, $adminId, $motivo, $id]);
    }

    /** ¿El usuario ya tiene todos sus documentos aprobados? */
    public function todosAprobados(int $usuarioId, array $requeridos): bool
    {
        if ($requeridos === []) {
            return false;
        }

        $marcas = implode(',', array_fill(0, count($requeridos), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT tipo FROM kyc_documentos
             WHERE usuario_id = ? AND estado = 'aprobado' AND tipo IN ($marcas)"
        );
        $stmt->execute([$usuarioId, ...$requeridos]);

        $aprobados = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return count(array_unique($aprobados)) >= count($requeridos);
    }
}
