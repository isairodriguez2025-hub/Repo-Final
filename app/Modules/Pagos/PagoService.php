<?php

declare(strict_types=1);

namespace App\Modules\Pagos;

use App\Core\AuditLogger;
use App\Modules\Pedidos\PedidoService;
use PDO;
use RuntimeException;

/**
 * Pagos, dispersión de fondos y reembolsos (RN-09, RN-10, SEG-14).
 *
 * El dinero se calcula SIEMPRE aquí, con los importes guardados en el pedido.
 * Si la suma de lo que se reparte no cuadra con lo cobrado, la dispersión no
 * se ejecuta: es preferible detenerse que pagar de más.
 */
final class PagoService
{
    public const VENTANA_WEBHOOK_SEG = 300;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PedidoService $pedidos,
        private readonly PasarelaGateway $pasarela,
        private readonly AuditLogger $auditoria,
        private readonly array $config,
    ) {
    }

    /** Crea el pago en estado `creado` y la intención en la pasarela. */
    public function crearIntencion(int $checkoutId, string $metodo = 'tarjeta'): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM checkouts WHERE id = ? LIMIT 1');
        $stmt->execute([$checkoutId]);
        $checkout = $stmt->fetch();

        if ($checkout === false) {
            return ['ok' => false, 'mensaje' => 'El checkout no existe.'];
        }

        $intencion = $this->pasarela->crearIntencionPago($checkout, ['metodo' => $metodo]);

        $this->pdo->prepare(
            "INSERT INTO pagos (checkout_id, pasarela, referencia_externa, metodo, monto, estado)
             VALUES (?,?,?,?,?,'creado')
             ON DUPLICATE KEY UPDATE monto = VALUES(monto)"
        )->execute([
            $checkoutId, $this->pasarela->nombre(), $intencion['referencia'], $metodo, $checkout['total'],
        ]);

        return ['ok' => true, 'intencion' => $intencion];
    }

    /**
     * Procesa un webhook de la pasarela.
     *
     * Orden de las defensas: firma → antigüedad → idempotencia → efecto.
     * Nada se escribe en la base antes de pasar las tres primeras.
     *
     * @return array{ok:bool, codigo:string, mensaje:string}
     */
    public function procesarWebhook(string $payload, string $firma, string $timestamp, string $eventoId): array
    {
        if (!$this->pasarela->verificarFirmaWebhook($payload, $firma, $timestamp)) {
            return ['ok' => false, 'codigo' => 'FIRMA_INVALIDA', 'mensaje' => 'Firma o marca de tiempo inválida.'];
        }

        if ($eventoId === '' || strlen($eventoId) > 100) {
            return ['ok' => false, 'codigo' => 'EVENTO_INVALIDO', 'mensaje' => 'Falta el identificador del evento.'];
        }

        // Si ya se procesó, se responde 200 sin volver a aplicarlo.
        $stmt = $this->pdo->prepare('SELECT id FROM webhook_eventos WHERE pasarela = ? AND evento_id = ? LIMIT 1');
        $stmt->execute([$this->pasarela->nombre(), $eventoId]);

        if ($stmt->fetchColumn() !== false) {
            return ['ok' => true, 'codigo' => 'REPETIDO', 'mensaje' => 'Evento ya procesado.'];
        }

        $datos = json_decode($payload, true);

        if (!is_array($datos) || !isset($datos['referencia'])) {
            return ['ok' => false, 'codigo' => 'PAYLOAD_INVALIDO', 'mensaje' => 'El cuerpo no trae una referencia de pago.'];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM pagos WHERE referencia_externa = ? LIMIT 1');
        $stmt->execute([(string) $datos['referencia']]);
        $pago = $stmt->fetch();

        if ($pago === false) {
            return ['ok' => false, 'codigo' => 'PAGO_NO_ENCONTRADO', 'mensaje' => 'La referencia no corresponde a ningún pago.'];
        }

        $tipo = (string) ($datos['tipo'] ?? 'pago.aprobado');

        $this->pdo->beginTransaction();

        try {
            $resultado = match ($tipo) {
                'pago.aprobado' => $this->aplicarAprobado($pago, (string) ($datos['metodo'] ?? 'tarjeta')),
                'pago.rechazado' => $this->aplicarRechazado($pago),
                default => 'IGNORADO',
            };

            $this->pdo->prepare(
                'INSERT INTO webhook_eventos (pasarela, evento_id, tipo, payload, resultado) VALUES (?,?,?,?,?)'
            )->execute([$this->pasarela->nombre(), $eventoId, $tipo, $payload, $resultado]);

            $this->pdo->prepare('UPDATE pagos SET ultimo_evento_id = ? WHERE id = ?')->execute([$eventoId, (int) $pago['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $this->auditoria->log('webhook_pago', null, 'pago', (string) $pago['id'], [
            'evento' => $eventoId, 'tipo' => $tipo, 'resultado' => $resultado,
        ], '0.0.0.0');

        return ['ok' => true, 'codigo' => $resultado, 'mensaje' => 'Evento procesado.'];
    }

    private function aplicarAprobado(array $pago, string $metodo): string
    {
        $this->pdo->prepare("UPDATE pagos SET estado = 'autorizado' WHERE id = ?")->execute([(int) $pago['id']]);

        // SPEI: el banco confirma antes de que el mayorista vea el pedido.
        $stmt = $this->pdo->prepare('SELECT id FROM pedidos WHERE checkout_id = ?');
        $stmt->execute([(int) $pago['checkout_id']]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pedidoId) {
            $this->pedidos->transicionar((int) $pedidoId, 'pendiente_pago', null, ['sistema'], 'Pago confirmado');
            $this->pedidos->transicionar((int) $pedidoId, 'pago_autorizado', null, ['sistema'], "Pago autorizado ({$metodo})");
            $this->pedidos->transicionar((int) $pedidoId, 'pendiente_aceptacion', null, ['sistema'], 'En espera del mayorista');
        }

        return 'PAGO_AUTORIZADO';
    }

    private function aplicarRechazado(array $pago): string
    {
        $this->pdo->prepare("UPDATE pagos SET estado = 'fallido' WHERE id = ?")->execute([(int) $pago['id']]);

        $stmt = $this->pdo->prepare('SELECT id FROM pedidos WHERE checkout_id = ?');
        $stmt->execute([(int) $pago['checkout_id']]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pedidoId) {
            $this->pedidos->transicionar((int) $pedidoId, 'expirado', null, ['sistema'], 'Pago rechazado por la pasarela');
        }

        return 'PAGO_RECHAZADO';
    }

    /**
     * Ejecuta la captura cuando el mayorista acepta.
     */
    public function capturar(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM pagos p JOIN pedidos pe ON pe.checkout_id = p.checkout_id WHERE pe.id = ? LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $pago = $stmt->fetch();

        if ($pago === false) {
            return ['ok' => false, 'mensaje' => 'El pedido no tiene un pago asociado.'];
        }

        $resultado = $this->pasarela->capturarPago((string) $pago['referencia_externa']);
        $this->pdo->prepare("UPDATE pagos SET estado = 'capturado' WHERE id = ?")->execute([(int) $pago['id']]);

        return ['ok' => true, 'captura' => $resultado];
    }

    /**
     * Calcula el reparto de un pedido según RN-09.
     *
     * @return array{ok:bool, mensaje:string, detalle?:array<string,float>}
     */
    public function calcularDispersion(array $pedido): array
    {
        $subtotal = (float) $pedido['subtotal'];
        $iva = (float) $pedido['iva'];
        $envio = (float) $pedido['envio'];

        $pctMayorista = (float) ($this->config['comision_mayorista_pct'] ?? 0);
        $pctLogistica = (float) ($this->config['comision_logistica_pct'] ?? 0);
        $cargoServicio = (float) ($this->config['cargo_servicio'] ?? 0);
        $pctPasarela = (float) ($this->config['comision_pasarela_pct'] ?? 0);

        $comisionMayorista = $this->redondear($subtotal * $pctMayorista / 100);
        $comisionLogistica = $this->redondear($envio * $pctLogistica / 100);
        $comisionPasarela = $this->redondear(($subtotal + $iva + $envio + $cargoServicio) * $pctPasarela / 100);

        $totalCobrado = $this->redondear($subtotal + $iva + $envio + $cargoServicio);
        $dispMayorista = $this->redondear($subtotal + $iva - $comisionMayorista);
        $dispRepartidor = $this->redondear($envio - $comisionLogistica);
        $dispPlataforma = $this->redondear($comisionMayorista + $comisionLogistica + $cargoServicio - $comisionPasarela);

        $suma = $this->redondear($dispMayorista + $dispRepartidor + $dispPlataforma + $comisionPasarela);

        if ($suma !== $totalCobrado) {
            return [
                'ok'      => false,
                'mensaje' => "El cierre no cuadra: repartido {$suma} contra cobrado {$totalCobrado}.",
            ];
        }

        return [
            'ok'     => true,
            'mensaje' => 'Cierre correcto.',
            'detalle' => [
                'total_cobrado'       => $totalCobrado,
                'disp_mayorista'      => $dispMayorista,
                'disp_repartidor'     => $dispRepartidor,
                'disp_plataforma'     => $dispPlataforma,
                'comision_mayorista'  => $comisionMayorista,
                'comision_logistica'  => $comisionLogistica,
                'comision_servicio'   => $cargoServicio,
                'comision_pasarela'   => $comisionPasarela,
                'porcentaje_mayorista' => $pctMayorista,
                'porcentaje_logistica' => $pctLogistica,
            ],
        ];
    }

    /** Registra dispersiones y comisiones al completarse el pedido. */
    public function dispersar(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();

        if ($pedido === false) {
            return ['ok' => false, 'mensaje' => 'El pedido no existe.'];
        }

        $calculo = $this->calcularDispersion($pedido);

        if (!$calculo['ok']) {
            $this->auditoria->log('dispersion_rechazada', null, 'pedido', (string) $pedidoId, $calculo, '0.0.0.0');

            return $calculo;
        }

        $d = $calculo['detalle'];

        $stmt = $this->pdo->prepare('SELECT usuario_id FROM mayoristas_perfil WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $pedido['mayorista_id']]);
        $usuarioMayorista = (int) $stmt->fetchColumn();

        $this->pdo->beginTransaction();

        try {
            $insertar = $this->pdo->prepare(
                'INSERT INTO pagos_dispersion (pedido_id, beneficiario_id, tipo, monto, referencia, estado)
                 VALUES (?,?,?,?,?,?)'
            );

            $insertar->execute([$pedidoId, $usuarioMayorista, 'mayorista', $d['disp_mayorista'], null, 'simulado']);
            $insertar->execute([$pedidoId, $usuarioMayorista, 'plataforma', $d['disp_plataforma'], null, 'simulado']);

            $comision = $this->pdo->prepare(
                'INSERT INTO comisiones (pedido_id, tipo, base, porcentaje, monto) VALUES (?,?,?,?,?)'
            );
            $comision->execute([$pedidoId, 'mayorista', (float) $pedido['subtotal'], $d['porcentaje_mayorista'], $d['comision_mayorista']]);
            $comision->execute([$pedidoId, 'logistica', (float) $pedido['envio'], $d['porcentaje_logistica'], $d['comision_logistica']]);
            $comision->execute([$pedidoId, 'servicio', 0, null, $d['comision_servicio']]);
            $comision->execute([$pedidoId, 'pasarela', $d['total_cobrado'], null, $d['comision_pasarela']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $this->auditoria->log('dispersion_ejecutada', null, 'pedido', (string) $pedidoId, $d, '0.0.0.0');

        return $calculo;
    }

    /**
     * Reembolso total o parcial (RN-10).
     */
    public function reembolsar(int $pedidoId, ?float $monto, string $motivo, ?int $actorId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM pagos p JOIN pedidos pe ON pe.checkout_id = p.checkout_id WHERE pe.id = ? LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $pago = $stmt->fetch();

        if ($pago === false) {
            return ['ok' => false, 'mensaje' => 'El pedido no tiene un pago asociado.'];
        }

        $disponible = (float) $pago['monto'];
        $monto = $monto === null ? $disponible : min($monto, $disponible);

        if ($monto <= 0) {
            return ['ok' => false, 'mensaje' => 'El monto del reembolso debe ser mayor que cero.'];
        }

        $resultado = $this->pasarela->reembolsar((string) $pago['referencia_externa'], number_format($monto, 2, '.', ''), $motivo);

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                'INSERT INTO reembolsos (pedido_id, pago_id, monto, motivo, referencia_externa, estado, creado_por)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$pedidoId, (int) $pago['id'], $monto, $motivo, $resultado['referencia'], 'procesado', $actorId]);

            $nuevo = $monto >= $disponible ? 'reembolsado' : 'reembolsado_parcial';
            $this->pdo->prepare('UPDATE pagos SET estado = ? WHERE id = ?')->execute([$nuevo, (int) $pago['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $this->auditoria->log('reembolso', $actorId, 'pedido', (string) $pedidoId, [
            'monto' => $monto, 'motivo' => $motivo,
        ], '0.0.0.0');

        return ['ok' => true, 'mensaje' => 'Reembolso procesado.', 'monto' => $monto];
    }

    private function redondear(float $valor): float
    {
        return round($valor, 2, PHP_ROUND_HALF_UP);
    }
}
