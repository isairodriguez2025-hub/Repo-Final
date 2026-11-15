<?php
/** @var callable $e */
/** @var string $csrf */
/** @var string $clave */
/** @var array $historial */

$filas = '';
foreach ($historial as $h) {
    $filas .= '<tr><td>' . $e(substr((string) $h['cambiado_at'], 0, 19)) . '</td>'
        . '<td>' . $e($h['admin_email'] ?? 'desconocido') . '</td>'
        . '<td style="font-size:12px;word-break:break-all;max-width:200px">' . $e(substr((string) $h['valor_anterior'], 0, 60)) . '</td>'
        . '<td style="font-size:12px;word-break:break-all;max-width:200px">' . $e(substr((string) $h['valor_nuevo'], 0, 60)) . '</td>'
        . '<td style="text-align:right">v' . $h['version'] . '</td>'
        . '<td>' . $e($h['ip'] ?? '') . '</td></tr>';
}

$contenido = '<h1>Historial: <code>' . $e($clave) . '</code></h1>'
    . '<a class="boton boton--claro" href="/admin/parametros">← Volver a parámetros</a>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto;margin-top:16px">'
    . '<table><thead><tr><th>Fecha</th><th>Admin</th><th>Anterior</th><th>Nuevo</th><th style="text-align:right">Versión</th><th>IP</th></tr></thead>'
    . '<tbody>' . ($filas ?: '<tr><td colspan="6" style="color:#9ca3af;text-align:center">Sin historial.</td></tr>') . '</tbody></table></div>';

require __DIR__ . '/../layout.php';
