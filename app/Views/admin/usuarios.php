<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $usuarios */
/** @var string $busqueda */
/** @var string $rol */

$filas = '';
foreach ($usuarios as $u) {
    $roles = $e($u['roles'] ?? 'sin rol');
    $estado = $u['estado'] === 'activo'
        ? '<span style="color:#1f4d3a">activo</span>'
        : '<span style="color:#b91c1c">suspendido</span>';
    $filas .= '<tr><td><a href="/admin/usuarios/' . $u['id'] . '">#' . $u['id'] . '</a></td>'
        . '<td>' . $e($u['email']) . '</td><td>' . $estado . '</td>'
        . '<td>' . $roles . '</td>'
        . '<td>' . $e(substr((string) $u['creado'], 0, 10)) . '</td>'
        . '<td><a class="boton boton--claro" style="padding:4px 10px;font-size:12px" href="/admin/usuarios/' . $u['id'] . '">Ver</a></td></tr>';
}

if ($filas === '') {
    $filas = '<tr><td colspan="6" style="color:#9ca3af;text-align:center">Sin usuarios.</td></tr>';
}

$contenido = '<h1>Usuarios</h1>'
    . '<form method="get" action="/admin/usuarios" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:12px;margin:18px 0">'
    . '<input name="q" value="' . $e($busqueda) . '" placeholder="Buscar por correo…" style="flex:1;min-width:200px">'
    . '<button type="submit">Buscar</button>'
    . '</form>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto">'
    . '<table><thead><tr><th>ID</th><th>Correo</th><th>Estado</th><th>Roles</th><th>Creado</th><th></th></tr></thead>'
    . '<tbody>' . $filas . '</tbody></table></div>';

require __DIR__ . '/../layout.php';
