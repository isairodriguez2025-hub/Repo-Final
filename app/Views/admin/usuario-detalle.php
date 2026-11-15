<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $usuario */
/** @var array $pedidos */

$pedidosFilas = '';
foreach ($pedidos as $p) {
    $estado = match ($p['estado']) {
        'completado' => '<span style="color:#1f4d3a">completado</span>',
        'en_disputa' => '<span style="color:#b91c1c">en disputa</span>',
        'cancelado' => '<span style="color:#6b7280">cancelado</span>',
        default => $e($p['estado']),
    };
    $pedidosFilas .= '<tr><td>' . $e(substr((string) $p['uuid'], 0, 8)) . '</td>'
        . '<td style="text-align:right">' . '$' . number_format((float) $p['total'], 2) . '</td>'
        . '<td>' . $estado . '</td>'
        . '<td>' . $e(substr((string) $p['creado'], 0, 10)) . '</td></tr>';
}

$contenido = '<h1>Usuario #' . $usuario['id'] . '</h1>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;margin-bottom:16px">'
    . '<div style="display:flex;gap:28px;flex-wrap:wrap">'
    . '<div><strong>Correo:</strong> ' . $e($usuario['email']) . '</div>'
    . '<div><strong>Estado:</strong> ' . ($usuario['estado'] === 'activo' ? 'activo' : 'suspendido') . '</div>'
    . '<div><strong>Roles:</strong> ' . $e($usuario['roles'] ?? 'sin rol') . '</div>'
    . '<div><strong>Creado:</strong> ' . $e(substr((string) $usuario['created_at'], 0, 10)) . '</div>'
    . '</div></div>'
    . '<h2>Pedidos recientes</h2>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto">'
    . '<table><thead><tr><th>Pedido</th><th style="text-align:right">Total</th><th>Estado</th><th>Fecha</th></tr></thead>'
    . '<tbody>' . ($pedidosFilas ?: '<tr><td colspan="4" style="color:#9ca3af;text-align:center">Sin pedidos.</td></tr>') . '</tbody></table></div>'
    . '<div style="margin-top:16px;display:flex;gap:10px">'
    . '<form method="post" action="/admin/usuarios/' . $usuario['id'] . '/rol" style="display:flex;gap:8px;align-items:center">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<label>Rol: <select name="rol"><option value="comercio"' . (str_contains($usuario['roles'] ?? '', 'comercio') ? ' selected' : '') . '>comercio</option><option value="mayorista"' . (str_contains($usuario['roles'] ?? '', 'mayorista') ? ' selected' : '') . '>mayorista</option><option value="admin_total"' . (str_contains($usuario['roles'] ?? '', 'admin_total') ? ' selected' : '') . '>admin_total</option><option value="repartidor"' . (str_contains($usuario['roles'] ?? '', 'repartidor') ? ' selected' : '') . '>repartidor</option></select></label>'
    . '<button type="submit" class="boton boton--claro" style="padding:6px 12px">Actualizar</button>'
    . '</form>'
    . '<form method="post" action="/admin/usuarios/' . $usuario['id'] . '/estado" style="display:flex;gap:8px;align-items:center">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<input type="hidden" name="estado" value="' . ($usuario['estado'] === 'activo' ? 'suspendido' : 'activo') . '">'
    . '<button type="submit" class="boton" style="background:' . ($usuario['estado'] === 'activo' ? '#b91c1c' : '#1f4d3a') . '">' . ($usuario['estado'] === 'activo' ? 'Suspender' : 'Reactivar') . '</button>'
    . '</form></div>';

require __DIR__ . '/../layout.php';
