<?php
/**
 * Página de ejemplo del núcleo: demuestra el escape automático y el nonce.
 *
 * @var callable $e
 * @var string   $nonce
 * @var string   $mensaje
 */
$contenido = '<h1>' . $e($mensaje) . '</h1>'
    . '<p>Esta página se renderizó con la plantilla base. El nonce de la CSP es '
    . '<code>' . $e($nonce) . '</code> y solo ese valor autoriza scripts inline.</p>'
    . '<p>Prueba de escape: <code>' . $e('<script>alert(1)</script>') . '</code></p>';

require __DIR__ . '/layout.php';
