<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $favoritos */
/** @var array $sugerencias */
/** @var string|null $mensaje */
/** @var string|null $error */

$porCarpeta = [];
foreach ($favoritos as $f) {
    $porCarpeta[$f['carpeta'] ?? 'General'][] = $f;
}

$bloques = '';
foreach ($porCarpeta as $carpeta => $items) {
    $filas = '';
    foreach ($items as $f) {
        $agotado = (int) $f['stock_disponible'] === 0;
        $filas .= '<tr>'
            . '<td><div style="font-weight:500">' . $e($f['nombre']) . '</div>'
            . '<div style="font-size:12px;color:#9ca3af">' . $e($f['mayorista']) . ' · ' . $e($f['categoria']) . '</div></td>'
            . '<td style="font-variant-numeric:tabular-nums">$' . number_format((float) $f['precio_base'], 2) . '</td>'
            . '<td>' . ($agotado
                ? '<span style="font-size:12px;color:#b91c1c">agotado</span>'
                : '<span style="font-size:12px;color:#1f4d3a">' . (int) $f['stock_disponible'] . ' disp.</span>') . '</td>'
            . '<td style="text-align:right">'
            . ($agotado ? '' :
                '<form method="post" action="/carrito/agregar" style="display:inline;margin:0 8px 0 0">'
                . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
                . '<input type="hidden" name="producto" value="' . $e($f['uuid']) . '">'
                . '<input type="hidden" name="cantidad" value="' . (int) $f['cantidad_minima'] . '">'
                . '<input type="hidden" name="volver" value="/favoritos">'
                . '<button type="submit" style="padding:6px 12px;font-size:13px">Agregar</button></form>')
            . '<form method="post" action="/favoritos/quitar" style="display:inline;margin:0">'
            . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
            . '<input type="hidden" name="producto" value="' . $e($f['uuid']) . '">'
            . '<button type="submit" style="background:none;border:0;color:#b91c1c;font-size:13px;cursor:pointer;padding:0">Quitar</button>'
            . '</form></td></tr>';
    }

    $bloques .= '<h2>' . $e($carpeta) . '</h2>'
        . '<table><thead><tr><th>Producto</th><th>Precio</th><th>Stock</th><th></th></tr></thead>'
        . '<tbody>' . $filas . '</tbody></table>';
}

if ($bloques === '') {
    $bloques = '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:32px;text-align:center">'
        . '<p style="margin:0 0 14px">Todavía no tienes favoritos.</p>'
        . '<a class="boton" href="/buscar">Buscar productos</a></div>';
}

$sug = '';
foreach ($sugerencias as $s) {
    $sug .= '<li style="margin-bottom:6px">' . $e($s['producto'])
        . ' <span style="color:#9ca3af;font-size:13px">— hace ' . (int) $s['dias'] . ' días de tu última compra</span></li>';
}
if ($sug !== '') {
    $sug = '<h2>Es momento de reabastecer</h2><ul style="padding-left:18px;margin:0">' . $sug . '</ul>';
}

$contenido = '<h1>Favoritos</h1>'
    . ($mensaje !== null ? '<div class="aviso aviso--ok">' . $e($mensaje) . '</div>' : '')
    . ($error !== null ? '<div class="aviso aviso--error">' . $e($error) . '</div>' : '')
    . '<div style="margin-top:18px">' . $bloques . '</div>' . $sug;

require __DIR__ . '/../layout.php';
