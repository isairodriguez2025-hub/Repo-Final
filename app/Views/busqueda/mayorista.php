<?php
/** @var callable $e */
/** @var array $perfil */
$filas = '';
foreach ($perfil['productos'] as $p) {
    $filas .= '<tr><td><div style="font-weight:500">' . $e($p['nombre']) . '</div>'
        . '<div style="font-size:12px;color:#9ca3af">' . $e($p['categoria']) . '</div></td>'
        . '<td style="font-variant-numeric:tabular-nums">$' . number_format((float) $p['precio_base'], 2) . '</td>'
        . '<td>' . $e($p['unidad_venta']) . ' · ' . $e($p['contenido']) . '</td>'
        . '<td>' . (int) $p['stock_disponible'] . '</td></tr>';
}
if ($filas === '') {
    $filas = '<tr><td colspan="4"><div class="vacio">Sin productos disponibles.</div></td></tr>';
}

$horario = ($perfil['horario_apertura'] && $perfil['horario_cierre'])
    ? substr((string) $perfil['horario_apertura'], 0, 5) . ' – ' . substr((string) $perfil['horario_cierre'], 0, 5)
    : 'No especificado';

$reputacion = $perfil['reputacion'] === null ? 'Sin calificaciones' : number_format((float) $perfil['reputacion'], 1) . ' / 5';

$contenido = '<h1>' . $e($perfil['nombre_comercial']) . '</h1>'
    . '<p>' . $e($perfil['categoria_giro']) . ' · ' . $e($reputacion) . '</p>'
    . '<div style="display:flex;gap:28px;flex-wrap:wrap;margin:20px 0 28px;padding:16px;background:#fff;border:1px solid #e8e6df;border-radius:12px">'
    . '<div><div style="font-size:12px;color:#9ca3af">Horario</div><div style="font-weight:500">' . $e($horario) . '</div></div>'
    . '<div><div style="font-size:12px;color:#9ca3af">Pedido mínimo</div><div style="font-weight:500">$' . number_format((float) $perfil['monto_minimo'], 2) . '</div></div>'
    . '<div><div style="font-size:12px;color:#9ca3af">Productos</div><div style="font-weight:500">' . count($perfil['productos']) . '</div></div>'
    . '</div>'
    . '<h2>Catálogo</h2>'
    . '<table><thead><tr><th>Producto</th><th>Precio</th><th>Presentación</th><th>Stock</th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table>'
    . '<p style="margin-top:24px"><a href="/buscar">← Volver a la búsqueda</a></p>';

require __DIR__ . '/../layout.php';
