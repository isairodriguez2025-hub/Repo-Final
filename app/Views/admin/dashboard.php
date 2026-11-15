<?php
/** @var callable $e */
/** @var string $csrf */
/** @var int $totalUsuarios */
/** @var int $totalComercios */
/** @var int $totalMayoristas */
/** @var int $pedidosHoy */
/** @var int $reclamosAbiertos */
/** @var int $kycPendientes */

$contenido = '<h1>Panel de administración</h1>'
    . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin:18px 0">'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Usuarios</div>'
    . '<div style="font-size:28px;font-weight:700">' . $totalUsuarios . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Comercios</div>'
    . '<div style="font-size:28px;font-weight:700">' . $totalComercios . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Mayoristas</div>'
    . '<div style="font-size:28px;font-weight:700">' . $totalMayoristas . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Pedidos hoy</div>'
    . '<div style="font-size:28px;font-weight:700">' . $pedidosHoy . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Reclamos abiertos</div>'
    . '<div style="font-size:28px;font-weight:700;color:#b91c1c">' . $reclamosAbiertos . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">KYC pendientes</div>'
    . '<div style="font-size:28px;font-weight:700;color:#a56a12">' . $kycPendientes . '</div></div>'
    . '</div>'
    . '<div style="display:flex;gap:12px;flex-wrap:wrap">'
    . '<a class="boton" href="/admin/usuarios">Gestionar usuarios</a>'
    . '<a class="boton" href="/admin/reclamos">Reclamos (' . $reclamosAbiertos . ')</a>'
    . '<a class="boton" href="/admin/parametros">Parámetros</a>'
    . '</div>';

require __DIR__ . '/../layout.php';
