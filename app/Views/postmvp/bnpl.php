<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $planes */
/** @var string $estadoFiltro */

$filas = '';
foreach ($planes as $p) {
    $estado = match ($p['estado']) {
        'pendiente' => '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:999px;font-size:12px">pendiente</span>',
        'activo' => '<span style="background:#fef3c7;color:#a56a12;padding:2px 8px;border-radius:999px;font-size:12px">activo</span>',
        'completado' => '<span style="background:#f0fdf4;color:#1f4d3a;padding:2px 8px;border-radius:999px;font-size:12px">completado</span>',
        'moroso' => '<span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:12px">moroso</span>',
        default => $e($p['estado']),
    };
    $filas .= '<tr><td>' . $e(substr((string) $p['uuid'], 0, 8)) . '</td>'
        . '<td>' . $e($p['mayorista']) . '</td>'
        . '<td style="text-align:right">$' . number_format($p['total'], 2) . '</td>'
        . '<td style="text-align:right">' . $p['cuotas'] . 'x $' . number_format($p['cuota_monto'], 2) . '</td>'
        . '<td style="text-align:right">cuota ' . $p['cuota_actual'] . '/' . $p['cuotas'] . '</td>'
        . '<td>' . $estado . '</td>'
        . '<td>' . $e(substr((string) $p['creado'], 0, 10)) . '</td></tr>';
}

$contenido = '<h1>Planes BNPL</h1>'
    . '<form method="get" action="/postmvp/bnpl" style="display:flex;gap:10px;align-items:center;background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:12px;margin:18px 0">'
    . '<label>Estado: <select name="estado"><option value="">todos</option>'
    . '<option value="pendiente"' . ($estadoFiltro === 'pendiente' ? ' selected' : '') . '>pendiente</option>'
    . '<option value="activo"' . ($estadoFiltro === 'activo' ? ' selected' : '') . '>activo</option>'
    . '<option value="completado"' . ($estadoFiltro === 'completado' ? ' selected' : '') . '>completado</option>'
    . '<option value="moroso"' . ($estadoFiltro === 'moroso' ? ' selected' : '') . '>moroso</option>'
    . '</select></label>'
    . '<button type="submit" class="boton boton--claro">Filtrar</button>'
    . '</form>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto">'
    . '<table><thead><tr><th>ID</th><th>Mayorista</th><th style="text-align:right">Total</th><th>Cuotas</th><th>Cuota actual</th><th>Estado</th><th>Fecha</th></tr></thead>'
    . '<tbody>' . ($filas ?: '<tr><td colspan="7" style="color:#9ca3af;text-align:center">Sin planes BNPL.</td></tr>') . '</tbody></table></div>';

require __DIR__ . '/../layout.php';
