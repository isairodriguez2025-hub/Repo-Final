<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $productos */
/** @var string $busqueda */
/** @var bool $puede */
/** @var string|null $mensaje */
$filas = '';
foreach ($productos as $p) {
    $estado = (int) $p['activo'] === 1
        ? '<span style="color:#1f4d3a">activo</span>'
        : '<span style="color:#a56a12">inactivo</span>';
    $filas .= '<tr style="border-bottom:1px solid #f0eee8">'
        . '<td style="padding:8px 0">' . $e($p['nombre']) . '<br><span style="font-size:12px;color:#6b7280">' . $e($p['categoria']) . '</span></td>'
        . '<td>$' . number_format((float) $p['precio_base'], 2) . '</td>'
        . '<td>' . (int) $p['stock_disponible'] . '</td>'
        . '<td>' . $estado . '</td>'
        . '<td style="text-align:right"><a href="/mayorista/productos/' . $e($p['uuid']) . '/editar" style="color:#1f4d3a">Editar</a></td>'
        . '</tr>';
}
if ($filas === '') {
    $filas = '<tr><td colspan="5" style="padding:16px 0;color:#6b7280">Todavía no tienes productos.</td></tr>';
}

$aviso = $puede
    ? ''
    : '<p style="background:#fdf6e6;border:1px solid #efe0bd;color:#7a5a12;padding:10px 12px;border-radius:8px">'
      . 'Tu cuenta aún no está verificada. Puedes capturar productos, pero no se publicarán hasta que el KYC esté aprobado.</p>';

$contenido = '<h1>Mis productos</h1>'
    . $aviso
    . ($mensaje !== null ? '<p style="background:#eaf1ec;border:1px solid #cfdcd3;padding:10px 12px;border-radius:8px">' . $e($mensaje) . '</p>' : '')
    . '<div style="display:flex;gap:10px;margin:16px 0;flex-wrap:wrap">'
    . '<a href="/mayorista/productos/nuevo" style="padding:10px 16px;background:#1f4d3a;color:#fff;border-radius:8px;text-decoration:none">Nuevo producto</a>'
    . '<form method="get" action="/mayorista/productos" style="display:flex;gap:8px">'
    . '<input name="q" value="' . $e($busqueda) . '" placeholder="Buscar…" style="height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit">'
    . '<button type="submit" style="height:40px;padding:0 14px;border:1px solid #e6e4dd;background:#fff;border-radius:8px;font:inherit;cursor:pointer">Buscar</button>'
    . '</form></div>'
    . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
    . '<thead><tr style="text-align:left;border-bottom:1px solid #e6e4dd"><th>Producto</th><th>Precio</th><th>Stock</th><th>Estado</th><th></th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table>'
    . '<h2 style="font-size:18px;margin-top:32px">Carga masiva</h2>'
    . '<p style="font-size:14px;color:#6b7280">CSV con columnas: sku, nombre, precio_base, stock_disponible, peso_kg, volumen_m3, unidad_venta, contenido, cantidad_minima, multiplo_venta.</p>'
    . '<form method="post" action="/mayorista/productos/csv" enctype="multipart/form-data" style="margin-top:10px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<input type="file" name="csv" accept=".csv" required style="font:inherit"> '
    . '<button type="submit" style="height:40px;padding:0 14px;background:#1f4d3a;color:#fff;border:0;border-radius:8px;font:inherit;cursor:pointer">Importar</button>'
    . '</form>';

require __DIR__ . '/../layout.php';
