<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $pedido */
/** @var array $lineas */
/** @var array $eventos */
/** @var bool $esMayorista */
/** @var bool $esComercio */
/** @var bool $esRepartidor */
/** @var string|null $mensaje */
/** @var string|null $error */

$ETIQUETA = [
    'borrador' => 'Borrador', 'pendiente_pago' => 'Esperando pago', 'pago_autorizado' => 'Pago autorizado',
    'pendiente_aceptacion' => 'Por aceptar', 'aceptado' => 'Aceptado', 'preparando' => 'Preparando',
    'listo_para_recoger' => 'Listo para recoger', 'buscando_repartidor' => 'Buscando repartidor',
    'repartidor_asignado' => 'Repartidor asignado', 'en_recoleccion' => 'En recolección',
    'en_camino' => 'En camino', 'en_puerta' => 'En puerta', 'entregado' => 'Entregado',
    'completado' => 'Completado', 'cancelado' => 'Cancelado', 'rechazado' => 'Rechazado',
    'expirado' => 'Expirado', 'en_disputa' => 'En disputa', 'reembolsado' => 'Reembolsado',
];

$filas = '';
foreach ($lineas as $l) {
    $filas .= '<tr><td>' . $e($l['nombre_producto'])
        . ($l['escala_aplicada'] ? '<div style="font-size:12px;color:#9ca3af">' . $e($l['escala_aplicada']) . '</div>' : '')
        . '</td><td>' . (int) $l['cantidad'] . '</td>'
        . '<td style="font-variant-numeric:tabular-nums">$' . number_format((float) $l['precio_unitario'], 2) . '</td>'
        . '<td style="font-variant-numeric:tabular-nums">$' . number_format((float) $l['subtotal'], 2) . '</td></tr>';
}

$linea = '';
foreach ($eventos as $ev) {
    $linea .= '<li style="margin-bottom:8px"><strong>' . $e($ETIQUETA[$ev['estado_nuevo']] ?? $ev['estado_nuevo']) . '</strong>'
        . ' <span style="color:#9ca3af;font-size:12px">' . $e(substr((string) $ev['created_at'], 0, 16)) . '</span>'
        . ($ev['nota'] ? '<div style="font-size:13px;color:#6b7280">' . $e($ev['nota']) . '</div>' : '')
        . '</li>';
}

$acciones = '';
$estado = (string) $pedido['estado'];

if ($esMayorista) {
    $boton = static function (string $accion, string $texto, string $clase = '') use ($e, $csrf, $pedido): string {
        return '<form method="post" action="/pedidos/' . $e($pedido['uuid']) . '/' . $accion . '" style="display:inline;margin:0 8px 0 0">'
            . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
            . '<button type="submit" class="' . $clase . '" style="padding:9px 16px;font-size:14px">' . $e($texto) . '</button></form>';
    };

    $acciones = match ($estado) {
        'pendiente_aceptacion' => $boton('aceptar', 'Aceptar pedido') . $boton('rechazar', 'Rechazar', 'boton--claro'),
        'aceptado'             => $boton('preparando', 'Marcar preparando'),
        'preparando'           => $boton('listo', 'Listo para recoger'),
        'listo_para_recoger'   => $boton('buscar-reparto', 'Buscar repartidor'),
        default                => '',
    };

    $acciones .= '<a class="boton boton--claro" href="/pedidos/' . $e($pedido['uuid']) . '/surtido" style="padding:9px 16px;font-size:14px">Hoja de surtido</a>';
}

if ($esComercio && $estado === 'borrador') {
    $acciones = '<form method="post" action="/pedidos/' . $e($pedido['uuid']) . '/pagar" style="display:inline;margin:0">'
        . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
        . '<button type="submit" style="padding:9px 16px;font-size:14px">Confirmar pago</button></form>';
}

// --- Vista del repartidor -------------------------------------------------
if ($esRepartidor) {
    $pinCampo = static function (string $accion, string $texto, string $etiqueta, int $largo) use ($e, $csrf, $pedido): string {
        return '<form method="post" action="/repartidor/pedidos/' . $e($pedido['uuid']) . '/' . $accion . '" style="display:flex;gap:8px;align-items:center;margin:0">'
            . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
            . '<input name="pin" inputmode="numeric" maxlength="' . $largo . '" placeholder="PIN de ' . $largo . ' dígitos" required'
            . ' style="height:42px;width:210px;letter-spacing:.3em;text-align:center">'
            . '<button type="submit" style="padding:9px 16px;font-size:14px">' . $e($texto) . '</button>'
            . '<span style="font-size:13px;color:#6b7280">' . $e($etiqueta) . '</span></form>';
    };

    $paso = static function (string $accion, string $texto) use ($e, $csrf, $pedido): string {
        return '<form method="post" action="/pedidos/' . $e($pedido['uuid']) . '/' . $accion . '" style="display:inline;margin:0 8px 0 0">'
            . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
            . '<button type="submit" style="padding:9px 16px;font-size:14px">' . $e($texto) . '</button></form>';
    };

    $acciones = match ($estado) {
        'repartidor_asignado' => $pinCampo('recolectar', 'Confirmar recolección', 'Pídelo al mayorista', 6),
        'en_recoleccion'      => $paso('en-camino', 'Salir a entregar'),
        'en_camino'           => $paso('en-puerta', 'Llegué al comercio'),
        'en_puerta'           => $pinCampo('entregar', 'Confirmar entrega', 'Pídelo al comercio', 4),
        default               => '',
    };
}

// El comercio ve su PIN cuando el repartidor está en la puerta.
$avisoPin = '';
if ($esComercio && $estado === 'en_puerta' && !empty($pedido['pin_entrega_visible'])) {
    $avisoPin = '<div class="aviso aviso--alerta" style="text-align:center">'
        . '<div style="font-size:13px">Da este código al repartidor</div>'
        . '<div style="font-size:34px;font-weight:700;letter-spacing:.3em;margin-top:4px">'
        . $e((string) $pedido['pin_entrega_visible']) . '</div></div>';
}

$contenido = '<h1>Pedido ' . $e(substr((string) $pedido['uuid'], 0, 8)) . '</h1>'
    . '<p>' . $e($pedido['mayorista']) . ' → ' . $e($pedido['comercio']) . '</p>'
    . ($mensaje !== null ? '<div class="aviso aviso--ok">' . $e($mensaje) . '</div>' : '')
    . ($error !== null ? '<div class="aviso aviso--error">' . $e($error) . '</div>' : '')
    . '<div style="display:flex;gap:24px;flex-wrap:wrap;margin:18px 0;padding:16px;background:#fff;border:1px solid #e8e6df;border-radius:12px">'
    . '<div><div style="font-size:12px;color:#9ca3af">Estado</div><div style="font-weight:600">' . $e($ETIQUETA[$estado] ?? $estado) . '</div></div>'
    . '<div><div style="font-size:12px;color:#9ca3af">Total</div><div style="font-weight:600">$' . number_format((float) $pedido['total'], 2) . '</div></div>'
    . '<div><div style="font-size:12px;color:#9ca3af">Entrega</div><div style="font-weight:500">' . $e($pedido['calle']) . ', ' . $e($pedido['ciudad']) . '</div></div>'
    . '</div>'
    . $avisoPin
    . ($acciones !== '' ? '<div style="margin:18px 0">' . $acciones . '</div>' : '')
    . '<h2>Productos</h2>'
    . '<table><thead><tr><th>Producto</th><th>Cant.</th><th>Unitario</th><th>Subtotal</th></tr></thead><tbody>' . $filas . '</tbody></table>'
    . '<div style="display:flex;justify-content:flex-end;gap:28px;margin-top:14px;font-size:14px;color:#6b7280">'
    . '<span>Subtotal $' . number_format((float) $pedido['subtotal'], 2) . '</span>'
    . '<span>IVA $' . number_format((float) $pedido['iva'], 2) . '</span>'
    . '<strong style="color:#1a1a1a">Total $' . number_format((float) $pedido['total'], 2) . '</strong></div>'
    . '<h2>Historial</h2><ul style="padding-left:18px;margin:0">' . $linea . '</ul>';

require __DIR__ . '/../layout.php';
