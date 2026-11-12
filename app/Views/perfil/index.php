<?php
/** @var callable $e */
/** @var string $csrf */
/** @var string $rol */
/** @var array|null $perfil */
/** @var array $direcciones */
/** @var array $kyc */
/** @var array $errores */
$nombre = $perfil['nombre_negocio'] ?? $perfil['nombre_comercial'] ?? 'Mi negocio';

$lista = '';
foreach ($direcciones as $d) {
    $lista .= '<li style="margin-bottom:8px">' . $e($d['calle']) . ', ' . $e((string) $d['colonia'])
        . ' · ' . $e($d['ciudad']) . ' (' . $e((string) $d['latitud']) . ', ' . $e((string) $d['longitud']) . ')'
        . ($d['es_principal'] ? ' <strong>· principal</strong>' : '') . '</li>';
}
if ($lista === '') {
    $lista = '<li style="color:#6b7280">Todavía no tienes direcciones guardadas.</li>';
}

$estadoKyc = $kyc['completo']
    ? '<p style="color:#1f4d3a"><strong>Verificación completa.</strong></p>'
    : '<p style="color:#a56a12">Faltan ' . count($kyc['faltantes']) . ' documento(s) por aprobar. '
      . '<a href="/kyc" style="color:#1f4d3a">Completar verificación</a></p>';

$contenido = '<h1>' . $e($nombre) . '</h1>'
    . '<p>Rol: <strong>' . $e($rol) . '</strong></p>'
    . '<h2 style="font-size:18px;margin-top:28px">Verificación</h2>' . $estadoKyc
    . '<h2 style="font-size:18px;margin-top:28px">Direcciones</h2>'
    . '<ul style="padding-left:18px">' . $lista . '</ul>'
    . '<h2 style="font-size:18px;margin-top:28px">Agregar dirección</h2>'
    . (isset($errores['direccion']) ? '<p style="color:#b91c1c">' . $e($errores['direccion']) . '</p>' : '')
    . '<form method="post" action="/perfil/direccion" style="margin-top:12px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<label style="display:block;margin-bottom:10px"><span style="font-size:13px;color:#4b5563">Calle y número</span>'
    . '<input name="calle" required style="width:100%;height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<label style="display:block;margin-bottom:10px"><span style="font-size:13px;color:#4b5563">Colonia</span>'
    . '<input name="colonia" style="width:100%;height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<label style="display:block;margin-bottom:10px"><span style="font-size:13px;color:#4b5563">Ciudad</span>'
    . '<input name="ciudad" required style="width:100%;height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<div style="display:flex;gap:10px;margin-bottom:10px">'
    . '<label style="flex:1"><span style="font-size:13px;color:#4b5563">Latitud</span>'
    . '<input name="latitud" type="number" step="0.000001" required style="width:100%;height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<label style="flex:1"><span style="font-size:13px;color:#4b5563">Longitud</span>'
    . '<input name="longitud" type="number" step="0.000001" required style="width:100%;height:40px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '</div>'
    . '<label style="display:block;margin-bottom:14px"><input type="checkbox" name="es_principal" value="1"> Marcar como principal</label>'
    . '<button type="submit" style="height:42px;padding:0 18px;background:#1f4d3a;color:#fff;border:0;border-radius:8px;font:inherit;cursor:pointer">Guardar dirección</button>'
    . '</form>';

require __DIR__ . '/../layout.php';
