<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $licitaciones */
/** @var string $estadoFiltro */

$filas = '';
foreach ($licitaciones as $l) {
    $estado = match ($l['estado']) {
        'abierta' => '<span style="background:#f0fdf4;color:#1f4d3a;padding:2px 8px;border-radius:999px;font-size:12px">abierta</span>',
        'cerrada' => '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:999px;font-size:12px">cerrada</span>',
        'adjudicada' => '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:999px;font-size:12px">adjudicada</span>',
        'cancelada' => '<span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:12px">cancelada</span>',
        default => $e($l['estado']),
    };
    $filas .= '<tr><td>' . $e(substr((string) $l['uuid'], 0, 8)) . '</td>'
        . '<td>' . $e($l['titulo']) . '</td>'
        . '<td>' . $e($l['mayorista']) . '</td>'
        . '<td>' . ($l['monto_minimo'] ? '$' . number_format($l['monto_minimo'], 2) : '—') . '</td>'
        . '<td>' . ($l['monto_maximo'] ? '$' . number_format($l['monto_maximo'], 2) : '—') . '</td>'
        . '<td>' . $estado . '</td>'
        . '<td>' . $e(substr((string) $l['vence_en'], 0, 10)) . '</td>'
        . '<td><a class="boton boton--claro" style="padding:4px 10px;font-size:12px" href="/postmvp/licitaciones/' . $l['id'] . '">Ver</a></td></tr>';
}

$contenido = '<h1>Licitaciones</h1>'
    . '<form method="get" action="/postmvp/licitaciones" style="display:flex;gap:10px;align-items:center;background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:12px;margin:18px 0">'
    . '<label>Estado: <select name="estado"><option value="">todos</option>'
    . '<option value="abierta"' . ($estadoFiltro === 'abierta' ? ' selected' : '') . '>abierta</option>'
    . '<option value="cerrada"' . ($estadoFiltro === 'cerrada' ? ' selected' : '') . '>cerrada</option>'
    . '<option value="adjudicada"' . ($estadoFiltro === 'adjudicada' ? ' selected' : '') . '>adjudicada</option>'
    . '<option value="cancelada"' . ($estadoFiltro === 'cancelada' ? ' selected' : '') . '>cancelada</option>'
    . '</select></label>'
    . '<button type="submit" class="boton boton--claro">Filtrar</button>'
    . '</form>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto">'
    . '<table><thead><tr><th>ID</th><th>Título</th><th>Mayorista</th><th>Mín</th><th>Máx</th><th>Estado</th><th>Vence</th><th></th></tr></thead>'
    . '<tbody>' . ($filas ?: '<tr><td colspan="8" style="color:#9ca3af;text-align:center">Sin licitaciones.</td></tr>') . '</tbody></table></div>';

require __DIR__ . '/../layout.php';
