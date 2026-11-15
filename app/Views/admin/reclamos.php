<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $reclamos */
/** @var string $estadoFiltro */

$filas = '';
foreach ($reclamos as $r) {
    $estado = match ($r['estado']) {
        'abierto' => '<span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:12px">abierto</span>',
        'respondido' => '<span style="background:#fdf6e6;color:#a56a12;padding:2px 8px;border-radius:999px;font-size:12px">respondido</span>',
        'resuelto' => '<span style="background:#f0fdf4;color:#1f4d3a;padding:2px 8px;border-radius:999px;font-size:12px">resuelto</span>',
        'rechazado' => '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:999px;font-size:12px">rechazado</span>',
        default => $e($r['estado']),
    };
    $filas .= '<tr><td>' . $e(substr((string) $r['uuid'], 0, 8)) . '</td>'
        . '<td>' . $e($r['tipo']) . '</td><td>' . $estado . '</td>'
        . '<td>' . $e($r['comercio']) . ' → ' . $e($r['mayorista']) . '</td>'
        . '<td style="text-align:right">$' . number_format((float) $r['pedido_total'], 2) . '</td>'
        . '<td>' . $e(substr((string) $r['creado'], 0, 10)) . '</td>'
        . '<td><a class="boton boton--claro" style="padding:4px 10px;font-size:12px" href="/admin/reclamos/' . $r['id'] . '">Ver</a></td></tr>';
}

if ($filas === '') {
    $filas = '<tr><td colspan="7" style="color:#9ca3af;text-align:center">Sin reclamos.</td></tr>';
}

$contenido = '<h1>Reclamos y disputas</h1>'
    . '<form method="get" action="/admin/reclamos" style="display:flex;gap:10px;align-items:center;background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:12px;margin:18px 0">'
    . '<label>Estado: <select name="estado"><option value="">todos</option>'
    . '<option value="abierto"' . ($estadoFiltro === 'abierto' ? ' selected' : '') . '>abierto</option>'
    . '<option value="respondido"' . ($estadoFiltro === 'respondido' ? ' selected' : '') . '>respondido</option>'
    . '<option value="resuelto"' . ($estadoFiltro === 'resuelto' ? ' selected' : '') . '>resuelto</option>'
    . '<option value="rechazado"' . ($estadoFiltro === 'rechazado' ? ' selected' : '') . '>rechazado</option>'
    . '</select></label>'
    . '<button type="submit" class="boton boton--claro">Filtrar</button>'
    . '</form>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto">'
    . '<table><thead><tr><th>Reclamo</th><th>Tipo</th><th>Estado</th><th>Comercio → Mayorista</th><th style="text-align:right">Total</th><th>Fecha</th><th></th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table></div>';

require __DIR__ . '/../layout.php';
