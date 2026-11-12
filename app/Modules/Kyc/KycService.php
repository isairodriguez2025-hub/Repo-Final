<?php

declare(strict_types=1);

namespace App\Modules\Kyc;

use App\Core\AuditLogger;
use App\Core\Upload;
use PDO;
use RuntimeException;

/**
 * Reglas del KYC: qué documento pide cada rol, cómo se sube y cómo se aprueba.
 */
final class KycService
{
    /** Documentos obligatorios por rol (capítulo 4 del documento). */
    public const REQUERIDOS = [
        'mayorista' => ['constancia_sat', 'identificacion', 'comprobante_domicilio'],
        'repartidor' => ['identificacion', 'licencia', 'tarjeta_circulacion', 'antecedentes', 'selfie'],
        'comercio' => [],
    ];

    public const TIPOS = [
        'identificacion', 'licencia', 'tarjeta_circulacion', 'antecedentes',
        'selfie', 'constancia_sat', 'comprobante_domicilio', 'rfc_documento',
    ];

    public function __construct(
        private readonly KycRepository $repo,
        private readonly AuditLogger $auditoria,
        private readonly PDO $pdo,
        private readonly string $directorioKyc,
    ) {
    }

    /**
     * @param array{tmp_name?:string,error?:int,size?:int,name?:string} $archivo
     * @return array{ok:bool, mensaje:string, id:?int}
     */
    public function subir(int $usuarioId, string $tipo, array $archivo, string $ip): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'mensaje' => 'Tipo de documento no válido.', 'id' => null];
        }

        try {
            $guardado = Upload::guardar($archivo, $this->directorioKyc);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage(), 'id' => null];
        }

        $id = $this->repo->crear($usuarioId, $tipo, $guardado['ruta'], $guardado['mime'], $guardado['bytes']);

        $this->auditoria->log('kyc_documento_subido', $usuarioId, 'kyc_documento', (string) $id, [
            'tipo'  => $tipo,
            'mime'  => $guardado['mime'],
            'bytes' => $guardado['bytes'],
        ], $ip);

        return ['ok' => true, 'mensaje' => 'Documento recibido. Queda en revisión.', 'id' => $id];
    }

    public function estado(int $usuarioId, string $rol): array
    {
        $documentos = $this->repo->listarPorUsuario($usuarioId);
        $requeridos = self::REQUERIDOS[$rol] ?? [];

        $porTipo = [];
        foreach ($documentos as $d) {
            $porTipo[$d['tipo']] = $d;
        }

        $faltantes = [];
        foreach ($requeridos as $tipo) {
            if (!isset($porTipo[$tipo]) || $porTipo[$tipo]['estado'] !== 'aprobado') {
                $faltantes[] = $tipo;
            }
        }

        return [
            'documentos' => $documentos,
            'requeridos' => $requeridos,
            'faltantes'  => $faltantes,
            'completo'   => $requeridos !== [] && $faltantes === [],
        ];
    }

    /** Documentos esperando revisión, para la cola de administración. */
    public function pendientes(int $limite = 50): array
    {
        return $this->repo->listarPendientes($limite);
    }

    public function aprobar(int $documentoId, int $adminId, string $ip): bool
    {
        $doc = $this->repo->buscar($documentoId);

        if ($doc === null) {
            return false;
        }

        $this->repo->marcarEstado($documentoId, 'aprobado', $adminId);
        $this->auditoria->log('kyc_aprobado', $adminId, 'kyc_documento', (string) $documentoId, [
            'usuario_id' => (int) $doc['usuario_id'],
            'tipo'       => $doc['tipo'],
        ], $ip);

        $this->actualizarEstadoPerfil((int) $doc['usuario_id']);

        return true;
    }

    public function rechazar(int $documentoId, int $adminId, string $motivo, string $ip): bool
    {
        $doc = $this->repo->buscar($documentoId);

        if ($doc === null) {
            return false;
        }

        $this->repo->marcarEstado($documentoId, 'rechazado', $adminId, mb_substr($motivo, 0, 500));
        $this->auditoria->log('kyc_rechazado', $adminId, 'kyc_documento', (string) $documentoId, [
            'usuario_id' => (int) $doc['usuario_id'],
            'tipo'       => $doc['tipo'],
            'motivo'     => $motivo,
        ], $ip);

        $this->actualizarEstadoPerfil((int) $doc['usuario_id']);

        return true;
    }

    /**
     * Recalcula el estado_kyc del perfil según los documentos aprobados.
     * Es lo que habilita (o bloquea) publicar productos y recibir viajes.
     */
    private function actualizarEstadoPerfil(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.nombre FROM usuario_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = ?'
        );
        $stmt->execute([$usuarioId]);
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($roles as $rol) {
            $requeridos = self::REQUERIDOS[$rol] ?? [];

            if ($requeridos === []) {
                continue;
            }

            $completo = $this->repo->todosAprobados($usuarioId, $requeridos);
            $tabla = $rol === 'mayorista' ? 'mayoristas_perfil' : 'repartidores_perfil';

            $stmt = $this->pdo->prepare("UPDATE {$tabla} SET estado_kyc = ? WHERE usuario_id = ?");
            $stmt->execute([$completo ? 'aprobado' : 'en_revision', $usuarioId]);

            if ($rol === 'mayorista') {
                $stmt = $this->pdo->prepare('UPDATE mayoristas_perfil SET activo = ? WHERE usuario_id = ?');
                $stmt->execute([$completo ? 1 : 0, $usuarioId]);
            }
        }
    }
}
