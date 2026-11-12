<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Registro de auditoría: quién hizo qué, cuándo y desde dónde.
 * Es obligatorio para acciones sensibles (login, KYC, cambios de precio).
 */
final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        string $accion,
        ?int $usuarioId = null,
        ?string $entidad = null,
        ?string $entidadId = null,
        ?array $detalle = null,
        ?string $ip = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (usuario_id, accion, entidad, entidad_id, ip, detalle)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $usuarioId,
            $accion,
            $entidad,
            $entidadId,
            self::ipBinaria($ip),
            $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** IPv4 e IPv6 se guardan como binario para ocupar menos y comparar rápido. */
    public static function ipBinaria(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $binaria = @inet_pton($ip);

        return $binaria === false ? null : $binaria;
    }
}
