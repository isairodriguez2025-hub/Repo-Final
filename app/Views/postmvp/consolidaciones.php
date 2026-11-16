<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $consolidaciones */
/** @var string $estadoFiltro */

$filas = '';
foreach ($consolidaciones as $c) {
    $estado = match ($c['estado']) {
        'activa' => '<span style="background:#f0fdf4;color:#1f4d3a;padding:2px 8px;border-radius:999px;font-size:12px">activa</span>',
        'completada' => '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:999px;font-size:12px">completada</span>',
        'cancelada' => '<span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:12px">cancelada</span>',
        default => $e($c['estado']),
    };
    $filas .= '<tr><td>' . $e(substr((string) $c['uuid'], 0, 8)) . '</td>'
        . '<td>' . $e($c['mayorista']) . '</td>'
        . '<td style="text-align:right">$' . number_format($c['flete_original'], 2) . '</td>'
        . '<td style="text-align:right">$' . number_format($c['flete_consolidado'], 2) . '</td>'
        . '<td style="text-align:right;color:#1f4d3a">$' . number_format($c['ahorro'], 2) . '</td>'
        . '<td>' . $estado . '</td>'
        . '<td>' . $e(substr((string) $c['creado'], 0, 10)) . '</td></tr>';
}

$contenido = '<h1>Consolidación de fletes</h1>'
    . '<form method="get" action="/postmvp/consolidaciones" style="display:flex;gap:10px;align-items:center;background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:12px;margin:18px 0">'
    . '<label>Estado: <select name="estado"><option value="">todos</option>'
    . '<option value="activa"' . ($estadoFiltro === 'activa' ? ' selected' : '') . '>activa</option>'
    . '<option value="completada"' . ($estadoFiltro === 'completada' ? ' selected' : '') . '>completada</option>'
    . '<option value="cancelada"' . ($estadoFiltro === 'cancelada' ? ' selected' : '') . '>cancelada</option>'
    . '</select></label>'
    . '<button type="submit" class="boton boton--claro">Filtrar</button>'
    . '</form>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto">'
    . '<table><thead><tr><th>ID</th><th>Mayorista</th><th style="text-align:right">Flete original</th><th style="text-align:right">Flete consolidado</th><th style="text-align:right">Ahorro</th><th>Estado</th><th>Fecha</th></tr></thead>'
    . '<tbody>' . ($filas ?: '<tr><td colspan="7" style="color:#9ca3af;text-align:center">Sin consolidaciones.</td></tr>') . '</tbody></table></div>';

require __DIR__ . '/../layout.php';
