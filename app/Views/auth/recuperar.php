<?php
/** @var callable $e */
/** @var string $csrf */
/** @var string|null $mensaje */
/** @var string|null $enlace */
$contenido = '<h1>Recuperar contraseña</h1>'
    . '<p>Escribe tu correo y te enviamos un enlace para restablecerla.</p>'
    . ($mensaje !== null ? '<p style="background:#eaf1ec;border:1px solid #cfdcd3;padding:10px 12px;border-radius:8px">' . $e($mensaje) . '</p>' : '')
    . ($enlace !== null ? '<p><a href="' . $e($enlace) . '" style="color:#1f4d3a">Abrir el enlace de restablecimiento</a></p>' : '')
    . '<form method="post" action="/recuperar" style="margin-top:20px">'
    . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
    . '<label style="display:block;margin-bottom:18px"><span style="font-size:13px;color:#4b5563">Correo</span>'
    . '<input type="email" name="email" required style="width:100%;height:42px;padding:0 12px;border:1px solid #e6e4dd;border-radius:8px;font:inherit"></label>'
    . '<button type="submit" style="width:100%;height:44px;background:#1f4d3a;color:#fff;border:0;border-radius:8px;font:inherit;font-weight:500;cursor:pointer">Enviar enlace</button>'
    . '</form>';

require __DIR__ . '/../layout.php';
