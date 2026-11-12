<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $categorias */
/** @var array|null $producto */
/** @var array $errores */
/** @var array $valores */
$v = static fn (string $k, $def = '') => $valores[$k] ?? $def;
$err = static fn (string $k) => isset($errores[$k]) ? '<span style="font-size:12px;color:#b91c1c">' . $e($errores[$k]) . '</span>' : '';
$accion = $producto === null ? '/mayorista/productos' : '/mayorista/productos/' . $e($producto['uuid']);

$opciones = '';
foreach ($categorias as $c) {
    $sel = ((int) $v('categoria_id', 0) === (int) $c['id']) ? ' selected' : '';
    $opciones .= '<option value="' . (int) $c['id'] . '"' . $sel . '>' . $e($c['nombre']) . '</option>';
}

$campo = static function (string $nombre, string $etiqueta, string $tipo, $valor, string $extra = '') use ($e, $err): string {
    return '<label style="display:block;margin-bottom:12px"><span style="font-size:13px;color:#4b5563">' . $e($etiqueta) . '</span>'
        . '<input type="' . $tipo . '" name="' . $nombre . '" value="' . $e((string) $valor) . '" ' . $extra
        . ' style="width:100%;height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit">'
        . $err($nombre) . '</label>';
};

$checks = '';
foreach (['perecedero' => 'Perecedero', 'requiere_frio' => 'Requiere frío', 'inflamable' => 'Inflamable', 'restringido' => 'Restringido', 'activo' => 'Publicar (activo)'] as $k => $texto) {
    $sel = !empty($v($k, false)) ? ' checked' : '';
    $checks .= '<label style="margin-right:14px;font-size:14px"><input type="checkbox" name="' . $k . '" value="1"' . $sel . '> ' . $e($texto) . '</label>';
}

$contenido = '<h1>' . $e($titulo) . '</h1>'
    . '<form method="post" action="' . $accion . '" style="margin-top:18px;max-width:560px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . $campo('nombre', 'Nombre', 'text', $v('nombre'), 'required maxlength="160"')
    . '<label style="display:block;margin-bottom:12px"><span style="font-size:13px;color:#4b5563">Categoría</span>'
    . '<select name="categoria_id" style="width:100%;height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit">' . $opciones . '</select>'
    . $err('categoria_id') . '</label>'
    . '<label style="display:block;margin-bottom:12px"><span style="font-size:13px;color:#4b5563">Descripción</span>'
    . '<textarea name="descripcion" rows="3" style="width:100%;padding:10px 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit">' . $e((string) $v('descripcion')) . '</textarea></label>'
    . '<div style="display:flex;gap:12px">'
    . '<div style="flex:1">' . $campo('precio_base', 'Precio sin IVA', 'number', $v('precio_base'), 'step="0.01" min="0.01" required') . '</div>'
    . '<div style="flex:1">' . $campo('iva_tasa', 'IVA %', 'number', $v('iva_tasa', 16), 'step="0.01" min="0" max="100"') . '</div>'
    . '</div>'
    . '<div style="display:flex;gap:12px">'
    . '<div style="flex:1">' . $campo('unidad_venta', 'Unidad de venta', 'text', $v('unidad_venta'), 'required placeholder="caja"') . '</div>'
    . '<div style="flex:1">' . $campo('contenido', 'Contenido', 'text', $v('contenido'), 'required placeholder="12 piezas"') . '</div>'
    . '</div>'
    . '<div style="display:flex;gap:12px">'
    . '<div style="flex:1">' . $campo('peso_kg', 'Peso (kg)', 'number', $v('peso_kg'), 'step="0.001" min="0.001" required') . '</div>'
    . '<div style="flex:1">' . $campo('volumen_m3', 'Volumen (m³)', 'number', $v('volumen_m3'), 'step="0.0001" min="0.0001" required') . '</div>'
    . '</div>'
    . '<div style="display:flex;gap:12px">'
    . '<div style="flex:1">' . $campo('cantidad_minima', 'Cantidad mínima', 'number', $v('cantidad_minima', 1), 'min="1" required') . '</div>'
    . '<div style="flex:1">' . $campo('multiplo_venta', 'Múltiplo de venta', 'number', $v('multiplo_venta', 1), 'min="1" required') . '</div>'
    . ($producto === null ? '<div style="flex:1">' . $campo('stock_inicial', 'Stock inicial', 'number', 0, 'min="0"') . '</div>' : '')
    . '</div>'
    . '<div style="margin:14px 0">' . $checks . '</div>'
    . '<button type="submit" style="height:44px;padding:0 20px;background:#1f4d3a;color:#fff;border:0;border-radius:8px;font:inherit;cursor:pointer">Guardar</button>'
    . ' <a href="/mayorista/productos" style="margin-left:10px;color:#6b7280">Cancelar</a>'
    . '</form>';

require __DIR__ . '/../layout.php';
