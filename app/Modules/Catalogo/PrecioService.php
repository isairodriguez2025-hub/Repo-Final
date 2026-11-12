<?php

declare(strict_types=1);

namespace App\Modules\Catalogo;

use App\Modules\Catalogo\Excepciones\EscalaInvalidaException;
use App\Modules\Catalogo\Excepciones\EscalaNoAplicaException;

/**
 * Precios, escalas de descuento e IVA (RN-01 y RN-03).
 *
 * Reglas que este servicio garantiza:
 *  - Todos los importes son MXN con 2 decimales, redondeo comercial HALF_UP.
 *  - El precio de catálogo está SIN IVA; el IVA se desglosa al calcular.
 *  - El precio de una escala se calcula, nunca se almacena.
 *  - La cantidad debe respetar el mínimo y el múltiplo de venta del producto.
 */
final class PrecioService
{
    public const IVA_DEFAULT = 16.00;

    /**
     * Precio unitario sin IVA aplicando la escala que corresponda.
     *
     * @param array<string, mixed> $producto fila de `productos`
     * @return array{precio_unitario:float, precio_con_iva:float, iva:float, escala_aplicada:?string, iva_tasa:float}
     * @throws EscalaNoAplicaException si la cantidad no respeta mínimo o múltiplo
     */
    public function calcularPrecioUnitario(array $producto, int $cantidad, array $escalas = []): array
    {
        $this->validarCantidad($producto, $cantidad);

        $base = (float) $producto['precio_base'];
        $ivaTasa = (float) ($producto['iva_tasa'] ?? self::IVA_DEFAULT);

        $precio = $base;
        $aplicada = null;

        // De la escala más alta a la más baja: la primera que la cantidad alcanza.
        usort($escalas, static fn (array $a, array $b): int => (int) $b['cantidad_minima'] <=> (int) $a['cantidad_minima']);

        foreach ($escalas as $escala) {
            if ($cantidad >= (int) $escala['cantidad_minima']) {
                $precio = $this->precioDeEscala($base, $escala);
                $aplicada = $this->describirEscala($escala);
                break;
            }
        }

        $precio = $this->redondear($precio);
        $iva = $this->redondear($precio * ($ivaTasa / 100));

        return [
            'precio_unitario' => $precio,
            'precio_con_iva'  => $this->redondear($precio + $iva),
            'iva'             => $iva,
            'escala_aplicada' => $aplicada,
            'iva_tasa'        => $ivaTasa,
        ];
    }

    /**
     * Desglose completo de una línea de pedido.
     *
     * @return array{precio_unitario:float, precio_con_iva:float, iva:float, escala_aplicada:?string,
     *               iva_tasa:float, cantidad:int, subtotal:float, iva_total:float, total:float}
     */
    public function calcularLinea(array $producto, int $cantidad, array $escalas = []): array
    {
        $unitario = $this->calcularPrecioUnitario($producto, $cantidad, $escalas);

        $subtotal = $this->redondear($unitario['precio_unitario'] * $cantidad);
        $ivaTotal = $this->redondear($unitario['iva'] * $cantidad);

        return $unitario + [
            'cantidad'  => $cantidad,
            'subtotal'  => $subtotal,
            'iva_total' => $ivaTotal,
            'total'     => $this->redondear($subtotal + $ivaTotal),
        ];
    }

    /**
     * Valida el conjunto de escalas de un producto.
     *
     * @param array<int, array{cantidad_minima:int|string, tipo:string, valor:float|string}> $escalas
     * @throws EscalaInvalidaException
     */
    public function validarEscalas(array $escalas): void
    {
        if ($escalas === []) {
            return;
        }

        $anterior = 0;

        foreach ($escalas as $escala) {
            $minimo = (int) ($escala['cantidad_minima'] ?? 0);
            $tipo = (string) ($escala['tipo'] ?? '');
            $valor = (float) ($escala['valor'] ?? 0);

            if ($minimo < 1) {
                throw new EscalaInvalidaException('La cantidad mínima de una escala debe ser al menos 1.');
            }

            if (!in_array($tipo, ['porcentaje', 'precio_fijo'], true)) {
                throw new EscalaInvalidaException("Tipo de escala no válido: '{$tipo}'.");
            }

            if ($valor <= 0) {
                throw new EscalaInvalidaException('El valor de una escala debe ser mayor que cero.');
            }

            if ($tipo === 'porcentaje' && $valor >= 100) {
                throw new EscalaInvalidaException('Un descuento porcentual debe ser menor que 100%.');
            }

            // Se valida en el orden recibido: [10, 5, 20] es inválido aunque
            // ordenado quedaría creciente. Así el mayorista ve el error tal
            // como capturó las escalas.
            if ($minimo <= $anterior) {
                throw new EscalaInvalidaException(
                    'Las escalas deben capturarse con cantidades mínimas estrictamente crecientes.'
                );
            }

            $anterior = $minimo;
        }
    }

    /**
     * La cantidad respeta el mínimo y el múltiplo de venta del producto.
     *
     * @throws EscalaNoAplicaException
     */
    public function validarCantidad(array $producto, int $cantidad): void
    {
        $minimo = (int) ($producto['cantidad_minima'] ?? 1);
        $multiplo = (int) ($producto['multiplo_venta'] ?? 1);

        if ($cantidad < $minimo) {
            throw new EscalaNoAplicaException("La cantidad mínima de venta es {$minimo}.");
        }

        if ($multiplo > 1 && $cantidad % $multiplo !== 0) {
            throw new EscalaNoAplicaException("La cantidad debe ser múltiplo de {$multiplo}.");
        }
    }

    /** Redondeo comercial a 2 decimales. */
    public function redondear(float $valor): float
    {
        return round($valor, 2, PHP_ROUND_HALF_UP);
    }

    private function precioDeEscala(float $base, array $escala): float
    {
        return $escala['tipo'] === 'porcentaje'
            ? $base * (1 - ((float) $escala['valor'] / 100))
            : (float) $escala['valor'];
    }

    private function describirEscala(array $escala): string
    {
        $minimo = (int) $escala['cantidad_minima'];

        return $escala['tipo'] === 'porcentaje'
            ? "Desde {$minimo} pzas: -{$escala['valor']}%"
            : "Desde {$minimo} pzas: \${$escala['valor']} c/u";
    }
}
