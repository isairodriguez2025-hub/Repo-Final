<?php
/** @var callable $e */
/** @var array $pedido */
/** @var array $lineas */
$filas = '';
foreach ($lineas as $l) {
    $filas .= '<tr><td style="border:1px solid #ccc;padding:8px">' . $e($l['nombre_producto']) . '</td>'
        . '<td style="border:1px solid #ccc;padding:8px;text-align:center;font-size:16px">' . (int) $l['cantidad'] . '</td>'
        . '<td style="border:1px solid #ccc;padding:8px;width:60px"></td></tr>';
}

$contenido = '<div style="max-width:720px">'
    . '<h1 style="margin-bottom:4px">Hoja de surtido</h1>'
    . '<p style="margin:0 0 18px">Pedido ' . $e(substr((string) $pedido['uuid'], 0, 8)) . ' · ' . $e(substr((string) $pedido['created_at'], 0, 16)) . '</p>'
    . '<p style="margin:0 0 4px"><strong>Comercio:</strong> ' . $e($pedido['comercio']) . '</p>'
    . '<p style="margin:0 0 18px"><strong>Entrega:</strong> ' . $e($pedido['calle']) . ', ' . $e((string) $pedido['colonia']) . ', ' . $e($pedido['ciudad']) . '</p>'
    . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
    . '<thead><tr><th style="border:1px solid #ccc;padding:8px;text-align:left">Producto</th>'
    . '<th style="border:1px solid #ccc;padding:8px;width:80px">Cantidad</th>'
    . '<th style="border:1px solid #ccc;padding:8px;width:60px">Surtido</th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table>'
    . '<p style="margin-top:24px;font-size:13px;color:#666">Firma de quien surte: ______________________</p>'
    . '<p style="margin-top:28px"><button onclick="window.print()" class="boton">Imprimir</button> '
    . '<a href="/mayorista/pedidos" style="margin-left:12px;font-size:14px">Volver</a></p>'
    . '</div>';

$estilos = '@media print { header.barra, .boton, a { display:none !important; } main { padding:0; } }';

require __DIR__ . '/../layout.php';
