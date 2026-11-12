<?php

declare(strict_types=1);

namespace App\Modules\Pagos;

/**
 * Pasarela simulada para desarrollo y demostración.
 *
 * Cumple el mismo contrato que la real y firma los webhooks con HMAC-SHA256 de
 * verdad, así que el camino crítico (firma, antigüedad, idempotencia) se prueba
 * igual que en producción. Solo el cobro es ficticio.
 */
final class SimuladoGateway implements PasarelaGateway
{
    public function __construct(
        private readonly string $secreto,
        private readonly int $ventanaSegundos = 300,
    ) {
    }

    public function nombre(): string
    {
        return 'simulado';
    }

    public function crearIntencionPago(array $checkout, array $metadatos): array
    {
        $referencia = 'sim_' . bin2hex(random_bytes(12));

        return [
            'ok'          => true,
            'referencia'  => $referencia,
            'url_pago'    => '/pago/simulado/' . $referencia,
            'metodo'      => $metadatos['metodo'] ?? 'tarjeta',
            'monto'       => $checkout['total'],
        ];
    }

    public function capturarPago(string $referenciaExterna): array
    {
        return ['ok' => true, 'referencia' => $referenciaExterna, 'estado' => 'capturado'];
    }

    public function reembolsar(string $referenciaExterna, string $monto, string $motivo): array
    {
        return [
            'ok'         => true,
            'referencia' => 'ref_' . bin2hex(random_bytes(8)),
            'monto'      => $monto,
            'motivo'     => $motivo,
            'estado'     => 'procesado',
        ];
    }

    public function dispersar(array $pedido, array $beneficiarios): array
    {
        return [
            'ok'     => true,
            'estado' => 'simulado',
            'detalle' => $beneficiarios,
        ];
    }

    /**
     * Firma esperada: HMAC-SHA256 de "{timestamp}.{payload}".
     * Además del HMAC se comprueba que el mensaje no tenga más de 5 minutos
     * (SEG-14): sin eso, un webhook capturado se podría reenviar para siempre.
     */
    public function verificarFirmaWebhook(string $payload, string $firma, string $timestamp): bool
    {
        if ($firma === '' || $timestamp === '' || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $this->ventanaSegundos) {
            return false;
        }

        $esperada = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secreto);

        return hash_equals($esperada, $firma);
    }

    /** Utilidad para pruebas y para el simulador de pago. */
    public function firmar(string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $this->secreto);
    }
}
