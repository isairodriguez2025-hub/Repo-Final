<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array<string,string> $errores */
/** @var array<string,string> $valores */
$campo = static function (string $nombre, string $etiqueta, string $tipo, array $valores, array $errores, callable $e): string {
    $error = $errores[$nombre] ?? null;
    $valor = $valores[$nombre] ?? '';

    return '<label style="display:block;margin-bottom:14px"><span style="font-size:13px;color:#4b5563">' . $e($etiqueta) . '</span>'
        . '<input type="' . $tipo . '" name="' . $nombre . '" value="' . $e((string) $valor) . '"'
        . ($tipo === 'password' ? ' minlength="12"' : '')
        . ' style="width:100%;height:42px;padding:0 12px;border:1px solid ' . ($error ? '#e05252' : '#e6e4dd') . ';border-radius:8px;font:inherit">'
        . ($error ? '<span style="font-size:12px;color:#b91c1c">' . $e($error) . '</span>' : '')
        . '</label>';
};

$opciones = '';
foreach (['comercio' => 'Comercio (compra)', 'mayorista' => 'Mayorista (vende)', 'repartidor' => 'Repartidor (transporta)'] as $valor => $texto) {
    $sel = (($valores['rol'] ?? 'comercio') === $valor) ? ' selected' : '';
    $opciones .= '<option value="' . $valor . '"' . $sel . '>' . $e($texto) . '</option>';
}

$contenido = '<h1>Crear cuenta</h1>'
    . '<p>Te enviaremos un correo para confirmar la cuenta.</p>'
    . '<form method="post" action="/registro" style="margin-top:20px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . $campo('nombre_negocio', 'Nombre del negocio', 'text', $valores, $errores, $e)
    . $campo('email', 'Correo', 'email', $valores, $errores, $e)
    . $campo('telefono', 'Teléfono (10 dígitos)', 'tel', $valores, $errores, $e)
    . $campo('password', 'Contraseña (mínimo 12)', 'password', $valores, $errores, $e)
    . '<label style="display:block;margin-bottom:18px"><span style="font-size:13px;color:#4b5563">Tipo de cuenta</span>'
    . '<select name="rol" style="width:100%;height:42px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit">' . $opciones . '</select></label>'
    . '<button type="submit" style="width:100%;height:44px;background:#1f4d3a;color:#fff;border:0;border-radius:8px;font:inherit;font-weight:500;cursor:pointer">Crear cuenta</button>'
    . '</form>'
    . '<p style="margin-top:18px;font-size:14px"><a href="/login" style="color:#1f4d3a">Ya tengo cuenta</a></p>';

require __DIR__ . '/../layout.php';
