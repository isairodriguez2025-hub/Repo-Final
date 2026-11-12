<?php
/** @var callable $e */
/** @var string $csrf */
/** @var array $pago */
$error = $error ?? null;
$contenido = '<h1>Pago del pedido</h1>'
    . '<div class="aviso aviso--alerta">Pasarela en modo simulado. No se cobra dinero real: '
    . 'sirve para probar el flujo completo, incluida la firma del webhook.</div>'
    . ($error !== null ? '<div class="aviso aviso--error">' . $e($error) . '</div>' : '')
    . '<div style="background:#fff;border:1px solid #e8e6df;border-radius:12px;padding:24px;max-width:420px">'
    . '<div style="font-size:13px;color:#6b7280">Total a pagar</div>'
    . '<div style="font-size:32px;font-weight:650;margin-bottom:6px">$' . number_format((float) $pago['monto'], 2) . '</div>'
    . '<div style="font-size:12px;color:#9ca3af;margin-bottom:18px">Referencia ' . $e((string) $pago['referencia_externa']) . '</div>'
    . '<form method="post" action="/pago/simulado/' . $e((string) $pago['referencia_externa']) . '">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<input type="hidden" name="monto" value="' . $e((string) $pago['monto']) . '">'
    . '<button type="submit" style="width:100%;height:46px">Pagar ahora</button>'
    . '</form></div>'
    . '<p style="margin-top:18px"><a href="/pedidos">Cancelar</a></p>';

require __DIR__ . '/../layout.php';
