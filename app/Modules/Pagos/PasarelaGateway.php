<?php

declare(strict_types=1);

namespace App\Modules\Pagos;

/**
 * Contrato de pasarela de pago (SEG-23).
 *
 * Ninguna implementación puede recibir ni guardar número de tarjeta, CVV o
 * cualquier dato sensible: el formulario de pago lo hospeda la pasarela y aquí
 * solo se manejan referencias.
 */
interface PasarelaGateway
{
    /** Crea la intención de pago y devuelve la URL a la que mandar al comercio. */
    public function crearIntencionPago(array $checkout, array $metadatos): array;

    /** Cobra una autorización previa. */
    public function capturarPago(string $referenciaExterna): array;

    /** Devuelve total o parcialmente el dinero. */
    public function reembolsar(string $referenciaExterna, string $monto, string $motivo): array;

    /** Reparte el dinero entre mayorista, repartidor y plataforma. */
    public function dispersar(array $pedido, array $beneficiarios): array;

    /** Verifica firma HMAC y antigüedad del mensaje. */
    public function verificarFirmaWebhook(string $payload, string $firma, string $timestamp): bool;

    public function nombre(): string;
}
