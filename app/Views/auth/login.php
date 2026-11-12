<?php
/** @var callable $e */
/** @var string $csrf */
/** @var string|null $error */
/** @var string $email */
$contenido = '<h1>Iniciar sesión</h1>'
    . '<p>Entra con el correo con el que te registraste.</p>'
    . ($error !== null ? '<p style="color:#b91c1c;background:#fef2f2;border:1px solid #f6d5d5;padding:10px 12px;border-radius:8px">' . $e($error) . '</p>' : '')
    . '<form method="post" action="/login" style="margin-top:20px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<label style="display:block;margin-bottom:14px"><span style="font-size:13px;color:#4b5563">Correo</span>'
    . '<input type="email" name="email" value="' . $e($email) . '" required autocomplete="email"'
    . ' style="width:100%;height:42px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<label style="display:block;margin-bottom:18px"><span style="font-size:13px;color:#4b5563">Contraseña</span>'
    . '<input type="password" name="password" required autocomplete="current-password"'
    . ' style="width:100%;height:42px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<button type="submit" style="width:100%;height:44px;background:#1f4d3a;color:#fff;border:0;border-radius:8px;font:inherit;font-weight:500;cursor:pointer">Entrar</button>'
    . '</form>'
    . '<p style="margin-top:18px;font-size:14px"><a href="/registro" style="color:#1f4d3a">Crear una cuenta</a>'
    . ' · <a href="/recuperar" style="color:#1f4d3a">Olvidé mi contraseña</a></p>';

require __DIR__ . '/../layout.php';
