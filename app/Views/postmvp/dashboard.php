<?php
/** @var callable $e */
/** @var string $csrf */
/** @var int $totalConsolidaciones */
/** @var int $planesBnplActivos */
/** @var int $licitacionesAbiertas */
/** @var float $ahorroTotal */

$contenido = '<h1>Post-MVP</h1>'
    . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin:18px 0">'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Consolidaciones activas</div>'
    . '<div style="font-size:28px;font-weight:700">' . $totalConsolidaciones . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Planes BNPL activos</div>'
    . '<div style="font-size:28px;font-weight:700">' . $planesBnplActivos . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Licitaciones abiertas</div>'
    . '<div style="font-size:28px;font-weight:700">' . $licitacionesAbiertas . '</div></div>'
    . '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;text-align:center">'
    . '<div style="font-size:13px;color:#9ca3af">Ahorro en fletes</div>'
    . '<div style="font-size:28px;font-weight:700;color:#1f4d3a">$' . number_format($ahorroTotal, 2) . '</div></div>'
    . '</div>'
    . '<div style="display:flex;gap:12px;flex-wrap:wrap">'
    . '<a class="boton" href="/postmvp/consolidaciones">Consolidar fletes</a>'
    . '<a class="boton" href="/postmvp/bnpl">Planes BNPL</a>'
    . '<a class="boton" href="/postmvp/licitaciones">Licitaciones</a>'
    . '</div>';

require __DIR__ . '/../layout.php';
