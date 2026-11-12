<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $pendientes */
$filas = '';
foreach ($pendientes as $p) {
    $filas .= '<tr style="border-bottom:1px solid #f0eee8">'
        . '<td style="padding:8px 0">' . $e($p['email']) . '</td>'
        . '<td>' . $e($p['tipo']) . '</td>'
        . '<td>' . $e(substr((string) $p['created_at'], 0, 16)) . '</td>'
        . '<td style="text-align:right">'
        . '<form method="post" action="/admin/kyc/' . (int) $p['id'] . '/aprobar" style="display:inline">'
        . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
        . '<button type="submit" style="background:#1f4d3a;color:#fff;border:0;border-radius:6px;padding:6px 12px;cursor:pointer">Aprobar</button></form> '
        . '<form method="post" action="/admin/kyc/' . (int) $p['id'] . '/rechazar" style="display:inline">'
        . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
        . '<input type="hidden" name="motivo" value="Documento ilegible">'
        . '<button type="submit" style="background:#fff;color:#b91c1c;border:1px solid #f6d5d5;border-radius:6px;padding:6px 12px;cursor:pointer">Rechazar</button></form>'
        . '</td></tr>';
}
if ($filas === '') {
    $filas = '<tr><td colspan="4" style="padding:16px 0;color:#6b7280">No hay documentos pendientes.</td></tr>';
}

$contenido = '<h1>Cola de verificación</h1>'
    . '<p>Documentos esperando revisión, del más antiguo al más reciente.</p>'
    . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:16px">'
    . '<thead><tr style="text-align:left;border-bottom:1px solid #e6e4dd"><th>Usuario</th><th>Documento</th><th>Recibido</th><th></th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table>';

require __DIR__ . '/../layout.php';
