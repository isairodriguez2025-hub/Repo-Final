<?php

declare(strict_types=1);

namespace App\Modules\PostMvp;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use PDO;

/**
 * Post-MVP: Consolidación de fletes, BNPL y licitaciones.
 *
 * - GET /postmvp                    → dashboard
 * - GET /postmvp/consolidaciones    → listado de consolidaciones de fletes
 * - POST /postmvp/consolidaciones   → crear consolidación
 * - GET /postmvp/bnpl               → planes BNPL
 * - POST /postmvp/bnpl              → crear plan BNPL
 * - POST /postmvp/bnpl/{id}/pagar   → registrar pago de cuota
 * - GET /postmvp/licitaciones       → listado de licitaciones
 * - POST /postmvp/licitaciones      → crear licitación
 * - POST /postmvp/licitaciones/{id}/cotizar → cotizar licitación
 */
final class PostMvpController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directorioVistas,
    ) {
    }

    private function vista(string $plantilla, array $datos, int $estado = 200): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $vista = new View($this->directorioVistas, $nonce);
        $datos['nonce'] ??= $nonce;
        $datos['csrf'] ??= \App\Core\Csrf::token();

        return Response::html($vista->render($plantilla, $datos), $estado)->withNonce($nonce);
    }

    private function usuarioId(): int
    {
        return (int) Session::obtener('user_id', 0);
    }

    private function roles(): array
    {
        $roles = Session::obtener('roles', []);
        return is_array($roles) ? array_values($roles) : [];
    }

    private function esAdmin(): bool
    {
        return in_array('admin_total', $this->roles(), true);
    }

    // ------------------------------------------------------------------
    // Dashboard Post-MVP
    // ------------------------------------------------------------------

    public function dashboard(): Response
    {
        $totalConsolidaciones = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM consolidacion_fletes WHERE estado = 'activa'"
        )->fetchColumn();

        $planesBnplActivos = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM bnpl_plan WHERE estado = 'activo'"
        )->fetchColumn();

        $licitacionesAbiertas = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM licitaciones WHERE estado = 'abierta' AND vence_en > NOW()"
        )->fetchColumn();

        $ahorroTotal = $this->pdo->query(
            "SELECT COALESCE(SUM(ahorro), 0) FROM consolidacion_fletes WHERE estado = 'activa'"
        )->fetchColumn();

        return $this->vista('postmvp/dashboard', [
            'titulo' => 'Post-MVP',
            'totalConsolidaciones' => $totalConsolidaciones,
            'planesBnplActivos' => $planesBnplActivos,
            'licitacionesAbiertas' => $licitacionesAbiertas,
            'ahorroTotal' => (float) $ahorroTotal,
        ]);
    }

    // ------------------------------------------------------------------
    // Consolidación de fletes
    // ------------------------------------------------------------------

    public function listarConsolidaciones(Request $peticion): Response
    {
        $estado = trim((string) $peticion->query('estado', ''));
        $sql = 'SELECT cf.*, m.nombre_comercial AS mayorista
                FROM consolidacion_fletes cf
                JOIN mayoristas_perfil m ON m.id = cf.mayorista_id';
        $params = [];

        if ($estado !== '' && in_array($estado, ['activa', 'completada', 'cancelada'], true)) {
            $sql .= ' WHERE cf.estado = :estado';
            $params[':estado'] = $estado;
        }

        $sql .= ' ORDER BY cf.creado_at DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $consolidaciones = [];
        foreach ($stmt->fetchAll() ?: [] as $f) {
            $consolidaciones[] = [
                'id' => (int) $f['id'],
                'uuid' => $f['uuid'],
                'mayorista' => $f['mayorista'],
                'pedidos_ids' => json_decode($f['pedidos_ids'], true) ?? [],
                'flete_original' => (float) $f['flete_original'],
                'flete_consolidado' => (float) $f['flete_consolidado'],
                'ahorro' => (float) $f['ahorro'],
                'estado' => $f['estado'],
                'creado' => $f['creado_at'],
            ];
        }

        return $this->vista('postmvp/consolidaciones', [
            'titulo' => 'Consolidación de fletes',
            'consolidaciones' => $consolidaciones,
            'estadoFiltro' => $estado,
        ]);
    }

    public function crearConsolidacion(Request $peticion): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $mayoristaId = (int) $peticion->input('mayorista_id', 0);
        $pedidosIds = $peticion->input('pedidos_ids', []);

        if ($mayoristaId === 0 || empty($pedidosIds) || !is_array($pedidosIds)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'DATOS_INVALIDOS', 'message' => 'Datos incompletos.']], 422);
        }

        if (count($pedidosIds) < 2) {
            return Response::json(['ok' => false, 'error' => ['code' => 'MINIMO_PEDIDOS', 'message' => 'Se requieren al menos 2 pedidos para consolidar.']], 422);
        }

        // Calcular flete original (suma de envíos individuales)
        $placeholders = implode(',', array_fill(0, count($pedidosIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT SUM(envio) AS total_envio FROM pedidos WHERE uuid IN ($placeholders) AND mayorista_id = ?"
        );
        $params = array_merge($pedidosIds, [$mayoristaId]);
        $stmt->execute($params);
        $fleteOriginal = (float) ($stmt->fetchColumn() ?: 0);

        // Flete consolidado: 70% del original (descuento por volumen)
        $fleteConsolidado = round($fleteOriginal * 0.7, 2);
        $ahorro = round($fleteOriginal - $fleteConsolidado, 2);

        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $this->pdo->prepare(
            'INSERT INTO consolidacion_fletes (uuid, mayorista_id, pedidos_ids, flete_original, flete_consolidado, ahorro)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$uuid, $mayoristaId, json_encode($pedidosIds), $fleteOriginal, $fleteConsolidado, $ahorro]);

        return Response::json([
            'ok' => true,
            'mensaje' => "Consolidación creada. Ahorro: \${$ahorro}",
            'uuid' => $uuid,
        ]);
    }

    // ------------------------------------------------------------------
    // BNPL (Buy Now Pay Later)
    // ------------------------------------------------------------------

    public function listarBnpl(Request $peticion): Response
    {
        $estado = trim((string) $peticion->query('estado', ''));
        $sql = 'SELECT bp.*, p.uuid AS pedido_uuid, p.total AS pedido_total,
                       m.nombre_comercial AS mayorista
                FROM bnpl_plan bp
                JOIN pedidos p ON p.id = bp.pedido_id
                JOIN mayoristas_perfil m ON m.id = p.mayorista_id';
        $params = [];

        if ($estado !== '' && in_array($estado, ['pendiente', 'activo', 'completado', 'moroso', 'cancelado'], true)) {
            $sql .= ' WHERE bp.estado = :estado';
            $params[':estado'] = $estado;
        }

        $sql .= ' ORDER BY bp.creado_at DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $planes = [];
        foreach ($stmt->fetchAll() ?: [] as $f) {
            $planes[] = [
                'id' => (int) $f['id'],
                'uuid' => $f['uuid'],
                'pedido_uuid' => $f['pedido_uuid'],
                'mayorista' => $f['mayorista'],
                'total' => (float) $f['total'],
                'cuotas' => (int) $f['cuotas'],
                'cuota_monto' => (float) $f['cuota_monto'],
                'cuota_actual' => (int) $f['cuota_actual'],
                'estado' => $f['estado'],
                'creado' => $f['creado_at'],
            ];
        }

        return $this->vista('postmvp/bnpl', [
            'titulo' => 'Planes BNPL',
            'planes' => $planes,
            'estadoFiltro' => $estado,
        ]);
    }

    public function crearBnpl(Request $peticion): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $pedidoId = (int) $peticion->input('pedido_id', 0);
        $cuotas = (int) $peticion->input('cuotas', 3);

        if ($pedidoId === 0) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PEDIDO_INVALIDO', 'message' => 'Pedido no válido.']], 422);
        }

        if ($cuotas < 2 || $cuotas > 12) {
            return Response::json(['ok' => false, 'error' => ['code' => 'CUOTAS_INVALIDAS', 'message' => 'Las cuotas deben estar entre 2 y 12.']], 422);
        }

        // Verificar que el pedido existe
        $stmt = $this->pdo->prepare('SELECT id, total, uuid FROM pedidos WHERE id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();

        if ($pedido === false) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PEDIDO_NO_ENCONTRADO', 'message' => 'Pedido no encontrado.']], 404);
        }

        $total = (float) $pedido['total'];
        $cuotaMonto = round($total / $cuotas, 2);
        $primeraCuota = date('Y-m-d');
        $ultimaCuota = date('Y-m-d', strtotime("+{$cuotas} months"));

        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $this->pdo->prepare(
            'INSERT INTO bnpl_plan (uuid, pedido_id, total, cuotas, cuota_monto, estado, primera_cuota, ultima_cuota)
             VALUES (?, ?, ?, ?, ?, "activo", ?, ?)'
        )->execute([$uuid, $pedidoId, $total, $cuotas, $cuotaMonto, $primeraCuota, $ultimaCuota]);

        $planId = (int) $this->pdo->lastInsertId();

        // Crear registros de cuotas
        for ($i = 1; $i <= $cuotas; $i++) {
            $vencimiento = date('Y-m-d', strtotime("+{$i} months"));
            $this->pdo->prepare(
                'INSERT INTO bnpl_pagos (bnpl_plan_id, cuota, monto, pagado) VALUES (?, ?, ?, FALSE)'
            )->execute([$planId, $i, $cuotaMonto]);
        }

        return Response::json([
            'ok' => true,
            'mensaje' => "Plan BNPL creado: {$cuotas} cuotas de \${$cuotaMonto}",
            'plan_id' => $planId,
        ]);
    }

    public function registrarPago(Request $peticion, array $args): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $planId = (int) $args['id'];
        $cuota = (int) $peticion->input('cuota', 0);
        $referencia = trim((string) $peticion->input('referencia', ''));

        if ($cuota < 1) {
            return Response::json(['ok' => false, 'error' => ['code' => 'CUOTA_INVALIDA', 'message' => 'Número de cuota no válido.']], 422);
        }

        // Verificar que la cuota existe y no está pagada
        $stmt = $this->pdo->prepare(
            'SELECT * FROM bnpl_pagos WHERE bnpl_plan_id = ? AND cuota = ? AND pagado = FALSE LIMIT 1'
        );
        $stmt->execute([$planId, $cuota]);
        $pago = $stmt->fetch();

        if ($pago === false) {
            return Response::json(['ok' => false, 'error' => ['code' => 'CUOTA_NO_ENCONTRADA', 'message' => 'Cuota no encontrada o ya pagada.']], 404);
        }

        // Registrar pago
        $this->pdo->prepare(
            'UPDATE bnpl_pagos SET pagado = TRUE, pagado_at = NOW(), referencia = ? WHERE id = ?'
        )->execute([$referencia, (int) $pago['id']]);

        // Verificar si todas las cuotas están pagadas
        $totalCuotas = (int) $this->pdo->query(
            "SELECT cuotas FROM bnpl_plan WHERE id = {$planId} LIMIT 1"
        )->fetchColumn();

        $pagadas = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM bnpl_pagos WHERE bnpl_plan_id = {$planId} AND pagado = TRUE"
        )->fetchColumn();

        if ($pagadas >= $totalCuotas) {
            $this->pdo->prepare('UPDATE bnpl_plan SET estado = "completado" WHERE id = ?')->execute([$planId]);
        }

        return Response::json(['ok' => true, 'mensaje' => "Cuota {$cuota} registrada como pagada."]);
    }

    // ------------------------------------------------------------------
    // Licitaciones
    // ------------------------------------------------------------------

    public function listarLicitaciones(Request $peticion): Response
    {
        $estado = trim((string) $peticion->query('estado', ''));
        $sql = 'SELECT l.*, m.nombre_comercial AS mayorista, c.nombre_negocio AS comercio_adjudicado
                FROM licitaciones l
                JOIN mayoristas_perfil m ON m.id = l.mayorista_id
                LEFT JOIN comercios_perfil c ON c.id = l.adjudicada_a';
        $params = [];

        if ($estado !== '' && in_array($estado, ['abierta', 'cerrada', 'adjudicada', 'cancelada'], true)) {
            $sql .= ' WHERE l.estado = :estado';
            $params[':estado'] = $estado;
        }

        $sql .= ' ORDER BY l.creado_at DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $licitaciones = [];
        foreach ($stmt->fetchAll() ?: [] as $f) {
            $licitaciones[] = [
                'id' => (int) $f['id'],
                'uuid' => $f['uuid'],
                'mayorista' => $f['mayorista'],
                'titulo' => $f['titulo'],
                'descripcion' => $f['descripcion'],
                'monto_minimo' => $f['monto_minimo'] ? (float) $f['monto_minimo'] : null,
                'monto_maximo' => $f['monto_maximo'] ? (float) $f['monto_maximo'] : null,
                'estado' => $f['estado'],
                'vence_en' => $f['vence_en'],
                'comercio_adjudicado' => $f['comercio_adjudicado'],
                'creado' => $f['creado_at'],
            ];
        }

        return $this->vista('postmvp/licitaciones', [
            'titulo' => 'Licitaciones',
            'licitaciones' => $licitaciones,
            'estadoFiltro' => $estado,
        ]);
    }

    public function crearLicitacion(Request $peticion): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $mayoristaId = (int) $peticion->input('mayorista_id', 0);
        $titulo = trim((string) $peticion->input('titulo', ''));
        $descripcion = trim((string) $peticion->input('descripcion', ''));
        $montoMinimo = $peticion->input('monto_minimo');
        $montoMaximo = $peticion->input('monto_maximo');
        $ventanaHoras = (int) $peticion->input('ventana_horas', 72);

        if ($mayoristaId === 0 || $titulo === '') {
            return Response::json(['ok' => false, 'error' => ['code' => 'DATOS_INVALIDOS', 'message' => 'Datos incompletos.']], 422);
        }

        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $venceEn = date('Y-m-d H:i:s', strtotime("+{$ventanaHoras} hours"));

        $this->pdo->prepare(
            'INSERT INTO licitaciones (uuid, mayorista_id, titulo, descripcion, monto_minimo, monto_maximo, vence_en)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uuid, $mayoristaId, $titulo, $descripcion,
            $montoMinimo !== null ? (float) $montoMinimo : null,
            $montoMaximo !== null ? (float) $montoMaximo : null,
            $venceEn]);

        return Response::json([
            'ok' => true,
            'mensaje' => "Licitación '{$titulo}' creada. Vence: {$venceEn}",
            'uuid' => $uuid,
        ]);
    }

    public function cotizarLicitacion(Request $peticion, array $args): Response
    {
        $licitacionId = (int) $args['id'];
        $comercioId = (int) $peticion->input('comercio_id', 0);
        $monto = (float) $peticion->input('monto', 0);
        $plazoEntrega = (int) $peticion->input('plazo_entrega', 0);
        $observaciones = trim((string) $peticion->input('observaciones', ''));

        if ($comercioId === 0 || $monto <= 0) {
            return Response::json(['ok' => false, 'error' => ['code' => 'DATOS_INVALIDOS', 'message' => 'Datos incompletos.']], 422);
        }

        // Verificar que la licitación existe y está abierta
        $stmt = $this->pdo->prepare('SELECT estado, vence_en FROM licitaciones WHERE id = ? LIMIT 1');
        $stmt->execute([$licitacionId]);
        $licitacion = $stmt->fetch();

        if ($licitacion === false) {
            return Response::json(['ok' => false, 'error' => ['code' => 'LICITACION_NO_ENCONTRADA', 'message' => 'Licitación no encontrada.']], 404);
        }
        if ($licitacion['estado'] !== 'abierta') {
            return Response::json(['ok' => false, 'error' => ['code' => 'LICITACION_CERRADA', 'message' => 'Esta licitación ya no está abierta.']], 409);
        }
        if (strtotime($licitacion['vence_en']) < time()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'LICITACION_VENCIDA', 'message' => 'Esta licitación ya venció.']], 409);
        }

        $this->pdo->prepare(
            'INSERT INTO licitaciones_cotizaciones (licitacion_id, comercio_id, monto, plazo_entrega, observaciones)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$licitacionId, $comercioId, $monto, $plazoEntrega > 0 ? $plazoEntrega : null, $observaciones]);

        return Response::json(['ok' => true, 'mensaje' => "Cotización registrada: \${$monto}"]);
    }
}
