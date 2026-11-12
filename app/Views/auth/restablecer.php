<?php
/** @var callable $e */
/** @var string $csrf */
/** @var string $token */
/** @var string|null $error */
$contenido = '<h1>Nueva contraseña</h1>'
    . ($error !== null ? '<p style="color:#b91c1c;background:#fef2f2;border:1px solid #f6d5d5;padding:10px 12px;border-radius:8px">' . $e($error) . '</p>' : '')
    . '<form method="post" action="/restablecer" style="margin-top:20px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<input type="hidden" name="token" value="' . $e($token) . '">'
    . '<label style="display:block;margin-bottom:18px"><span style="font-size:13px;color:#4b5563">Contraseña (mínimo 12)</span>'
    . '<input type="password" name="password" minlength="12" required style="width:100%;height:42px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<button type="submit" style="width:100%;height:44px;background:#1f4d3a;color:#fff;border:0;border-radius:8px;font:inherit;font-weight:500;cursor:pointer">Guardar</button>'
    . '</form>';

require __DIR__ . '/../layout.php';
