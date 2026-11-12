<?php
/** @var callable $e */
/** @var array $pedidos */
/** @var string $csrf */
$ETIQUETA = [
    'borrador' => 'Borrador', 'pendiente_pago' => 'Esperando pago', 'pago_autorizado' => 'Pago autorizado',
    'pendiente_aceptacion' => 'Por aceptar', 'aceptado' => 'Aceptado', 'preparando' => 'Preparando',
    'listo_para_recoger' => 'Listo', 'buscando_repartidor' => 'Buscando repartidor',
    'repartidor_asignado' => 'Repartidor asignado', 'en_recoleccion' => 'En recolección',
    'en_camino' => 'En camino', 'en_puerta' => 'En puerta', 'entregado' => 'Entregado',
    'completado' => 'Completado', 'cancelado' => 'Cancelado', 'rechazado' => 'Rechazado',
    'expirado' => 'Expirado', 'en_disputa' => 'En disputa', 'reembolsado' => 'Reembolsado',
];

$filas = '';
foreach ($pedidos as $p) {
    $estado = (string) $p['estado'];
    $accion = $estado === 'borrador'
        ? '<form method="post" action="/pedidos/' . $e($p['uuid']) . '/pagar" style="display:inline;margin:0">'
          . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
          . '<button type="submit" style="padding:6px 12px;font-size:13px">Pagar</button></form>'
        : '<a href="/pedidos/' . $e($p['uuid']) . '" style="font-size:13px">Ver</a>';

    $filas .= '<tr><td><div style="font-weight:500">' . $e($p['mayorista']) . '</div>'
        . '<div style="font-size:12px;color:#9ca3af">' . (int) $p['lineas'] . ' productos · ' . $e(substr((string) $p['created_at'], 0, 16)) . '</div></td>'
        . '<td style="font-variant-numeric:tabular-nums">$' . number_format((float) $p['total'], 2) . '</td>'
        . '<td style="font-size:13px">' . $e($ETIQUETA[$estado] ?? $estado) . '</td>'
        . '<td style="text-align:right">' . $accion . '</td></tr>';
}

if ($filas === '') {
    $filas = '<tr><td colspan="4"><div class="vacio">Todavía no has hecho pedidos.</div></td></tr>';
}

$contenido = '<h1>Mis pedidos</h1>'
    . '<table><thead><tr><th>Mayorista</th><th>Total</th><th>Estado</th><th></th></tr></thead><tbody>' . $filas . '</tbody></table>';

require __DIR__ . '/../layout.php';
