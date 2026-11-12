<?php
/** @var callable $e */
$contenido = '<h1>Carrito</h1>'
    . '<div class="aviso aviso--alerta">Tu cuenta no tiene perfil de comercio, así que no puede comprar. '
    . 'El carrito es para cuentas de comercio.</div>'
    . '<p><a href="/perfil">Ir a mi perfil</a></p>';

require __DIR__ . '/../layout.php';
