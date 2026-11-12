<?php
/** @var callable $e */
/** @var array $data */
$lista = '';
foreach ($data['pedidos'] as $p) {
    $lista .= '<li style="margin-bottom:6px"><a href="/pedidos/' . $e($p['uuid']) . '">Pedido ' . $e(substr((string) $p['uuid'], 0, 8)) . '</a>'
        . ' — $' . number_format((float) $p['total'], 2) . '</li>';
}

$contenido = '<h1>Pedido confirmado</h1>'
    . '<div class="aviso aviso--ok">Reservamos el stock por ' . $e((string) $data['expira_en']) . '. Confirma el pago para que el mayorista lo reciba.</div>'
    . '<div style="background:#fff;border:1px solid #e8e6df;border-radius:12px;padding:20px;margin-top:16px">'
    . '<div style="font-size:13px;color:#6b7280">Total</div>'
    . '<div style="font-size:28px;font-weight:650;margin-bottom:14px">$' . number_format((float) $data['total'], 2) . '</div>'
    . '<div style="font-size:13px;color:#6b7280;margin-bottom:6px">Pedidos generados (uno por mayorista)</div>'
    . '<ul style="padding-left:18px;margin:0">' . $lista . '</ul></div>'
    . '<p style="margin-top:20px"><a class="boton" href="/pedidos">Ver mis pedidos</a></p>';

require __DIR__ . '/../layout.php';
