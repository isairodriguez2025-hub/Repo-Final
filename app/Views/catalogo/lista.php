<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $productos */
/** @var string $busqueda */
/** @var bool $puede */
/** @var string|null $mensaje */
$filas = '';
foreach ($productos as $p) {
    $activo = (int) $p['activo'] === 1;
    $insignia = $activo
        ? '<span style="display:inline-block;font-size:12px;padding:2px 9px;border-radius:99px;background:#eaf1ec;color:#1f4d3a">activo</span>'
        : '<span style="display:inline-block;font-size:12px;padding:2px 9px;border-radius:99px;background:#f7f2e6;color:#8a6414">inactivo</span>';
    $stock = (int) $p['stock_disponible'];
    $stockColor = $stock === 0 ? '#b91c1c' : ($stock < 10 ? '#a56a12' : 'inherit');
    $filas .= '<tr>'
        . '<td><div style="font-weight:500">' . $e($p['nombre']) . '</div>'
        . '<div style="font-size:12px;color:#9ca3af">' . $e($p['categoria']) . '</div></td>'
        . '<td style="font-variant-numeric:tabular-nums">$' . number_format((float) $p['precio_base'], 2) . '</td>'
        . '<td style="font-variant-numeric:tabular-nums;color:' . $stockColor . '">' . $stock . '</td>'
        . '<td>' . $insignia . '</td>'
        . '<td style="text-align:right"><a href="/mayorista/productos/' . $e($p['uuid']) . '/editar" style="font-size:14px">Editar</a></td>'
        . '</tr>';
}
if ($filas === '') {
    $filas = '<tr><td colspan="5"><div class="vacio">Todavía no tienes productos. Empieza con <strong>Nuevo producto</strong> o carga un CSV.</div></td></tr>';
}

$aviso = $puede
    ? ''
    : '<div class="aviso aviso--alerta">Tu cuenta aún no está verificada. Puedes capturar productos, pero no se publicarán hasta que el KYC esté aprobado. <a href="/kyc">Completar verificación</a></div>';

$contenido = '<h1>Mis productos</h1>'
    . '<p>' . count($productos) . ' producto' . (count($productos) === 1 ? '' : 's') . ' en tu catálogo.</p>'
    . $aviso
    . ($mensaje !== null ? '<div class="aviso aviso--ok">' . $e($mensaje) . '</div>' : '')
    . '<div style="display:flex;gap:10px;margin:20px 0;flex-wrap:wrap;align-items:center">'
    . '<a class="boton" href="/mayorista/productos/nuevo">Nuevo producto</a>'
    . '<form method="get" action="/mayorista/productos" style="display:flex;gap:8px;flex:1;min-width:220px;margin:0">'
    . '<input name="q" value="' . $e($busqueda) . '" placeholder="Buscar por nombre o descripción…" style="height:42px">'
    . '<button type="submit" class="boton boton--claro" style="height:42px;white-space:nowrap">Buscar</button>'
    . '</form></div>'
    . '<table><thead><tr><th>Producto</th><th>Precio</th><th>Stock</th><th>Estado</th><th></th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table>'
    . '<h2>Carga masiva</h2>'
    . '<p style="font-size:14px">Sube un CSV con estas columnas: '
    . '<code>sku</code> <code>nombre</code> <code>precio_base</code> <code>stock_disponible</code> '
    . '<code>peso_kg</code> <code>volumen_m3</code> <code>unidad_venta</code> <code>contenido</code> '
    . '<code>cantidad_minima</code> <code>multiplo_venta</code>. Las filas con error se reportan por número de línea.</p>'
    . '<form method="post" action="/mayorista/productos/csv" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<input type="file" name="csv" accept=".csv" required style="max-width:320px">'
    . '<button type="submit" class="boton boton--claro">Importar CSV</button>'
    . '</form>';

require __DIR__ . '/../layout.php';
