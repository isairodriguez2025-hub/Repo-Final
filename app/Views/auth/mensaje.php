<?php
/** @var callable $e */
/** @var string $mensaje */
/** @var string|null $enlace */
$contenido = '<h1>' . $e($titulo) . '</h1>'
    . '<p>' . $e($mensaje) . '</p>'
    . ($enlace !== null
        ? '<p style="margin-top:20px"><a href="' . $e($enlace) . '" style="display:inline-block;padding:12px 18px;background:#1f4d3a;color:#fff;border-radius:8px;text-decoration:none">Continuar</a></p>'
        : '');

require __DIR__ . '/../layout.php';
