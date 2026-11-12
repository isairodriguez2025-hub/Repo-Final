<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $categorias */
/** @var array|null $producto */
/** @var array $errores */
/** @var array $valores */
$v = static fn (string $k, $def = '') => $valores[$k] ?? $def;
$err = static fn (string $k) => isset($errores[$k]) ? '<span class="error-campo">' . $e($errores[$k]) . '</span>' : '';
$accion = $producto === null ? '/mayorista/productos' : '/mayorista/productos/' . $e($producto['uuid']);

$opciones = '';
foreach ($categorias as $c) {
    $sel = ((int) $v('categoria_id', 0) === (int) $c['id']) ? ' selected' : '';
    $opciones .= '<option value="' . (int) $c['id'] . '"' . $sel . '>' . $e($c['nombre']) . '</option>';
}

$campo = static function (string $nombre, string $etiqueta, string $tipo, $valor, string $extra = '') use ($e, $err): string {
    return '<label><span>' . $e($etiqueta) . '</span>'
        . '<input type="' . $tipo . '" name="' . $nombre . '" value="' . $e((string) $valor) . '" ' . $extra . '>'
        . $err($nombre) . '</label>';
};

$checks = '';
foreach (['perecedero' => 'Perecedero', 'requiere_frio' => 'Requiere frío', 'inflamable' => 'Inflamable', 'restringido' => 'Restringido', 'activo' => 'Publicar'] as $k => $texto) {
    $sel = !empty($v($k, false)) ? ' checked' : '';
    $checks .= '<label style="display:inline-flex;align-items:center;gap:7px;margin:0 18px 0 0;font-size:14px">'
        . '<input type="checkbox" name="' . $k . '" value="1"' . $sel . '> ' . $e($texto) . '</label>';
}

$contenido = '<h1>' . $e($titulo) . '</h1>'
    . '<p>Los campos de precio, peso y volumen son obligatorios: sin ellos no se puede calcular el envío.</p>'
    . '<form method="post" action="' . $accion . '" style="margin-top:24px;max-width:600px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . $campo('nombre', 'Nombre del producto', 'text', $v('nombre'), 'required maxlength="160" placeholder="Café molido 1 kg"')
    . '<label><span>Categoría</span><select name="categoria_id">' . $opciones . '</select>' . $err('categoria_id') . '</label>'
    . '<label><span>Descripción</span><textarea name="descripcion" rows="3" placeholder="Opcional, pero ayuda a vender">' . $e((string) $v('descripcion')) . '</textarea></label>'
    . '<div style="display:flex;gap:14px">'
    . '<div style="flex:1">' . $campo('precio_base', 'Precio sin IVA', 'number', $v('precio_base'), 'step="0.01" min="0.01" required placeholder="185.50"') . '</div>'
    . '<div style="flex:1">' . $campo('iva_tasa', 'IVA %', 'number', $v('iva_tasa', 16), 'step="0.01" min="0" max="100"') . '</div>'
    . '</div>'
    . '<div style="display:flex;gap:14px">'
    . '<div style="flex:1">' . $campo('unidad_venta', 'Unidad de venta', 'text', $v('unidad_venta'), 'required placeholder="caja"') . '</div>'
    . '<div style="flex:1">' . $campo('contenido', 'Contenido', 'text', $v('contenido'), 'required placeholder="12 piezas"') . '</div>'
    . '</div>'
    . '<div style="display:flex;gap:14px">'
    . '<div style="flex:1">' . $campo('peso_kg', 'Peso (kg)', 'number', $v('peso_kg'), 'step="0.001" min="0.001" required placeholder="12.5"') . '</div>'
    . '<div style="flex:1">' . $campo('volumen_m3', 'Volumen (m³)', 'number', $v('volumen_m3'), 'step="0.0001" min="0.0001" required placeholder="0.02"') . '</div>'
    . '</div>'
    . '<div style="display:flex;gap:14px">'
    . '<div style="flex:1">' . $campo('cantidad_minima', 'Cantidad mínima', 'number', $v('cantidad_minima', 1), 'min="1" required') . '</div>'
    . '<div style="flex:1">' . $campo('multiplo_venta', 'Múltiplo de venta', 'number', $v('multiplo_venta', 1), 'min="1" required') . '</div>'
    . ($producto === null ? '<div style="flex:1">' . $campo('stock_inicial', 'Stock inicial', 'number', 0, 'min="0"') . '</div>' : '')
    . '</div>'
    . '<div style="margin:18px 0 22px;padding:14px;background:#fff;border:1px solid #e8e6df;border-radius:10px">'
    . '<div style="font-size:13px;color:#6b7280;margin-bottom:8px">Características</div>' . $checks . '</div>'
    . '<div style="display:flex;gap:12px;align-items:center">'
    . '<button type="submit">Guardar producto</button>'
    . '<a href="/mayorista/productos" style="font-size:14px;color:#6b7280;text-decoration:none">Cancelar</a>'
    . '</div></form>';

require __DIR__ . '/../layout.php';
