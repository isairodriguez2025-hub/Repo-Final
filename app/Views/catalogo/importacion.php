<?php
/** @var callable $e */
/** @var int $ok */
/** @var array $errores */
$lista = '';
foreach ($errores as $linea => $mensaje) {
    $lista .= '<li>Línea ' . (int) $linea . ': ' . $e($mensaje) . '</li>';
}

$contenido = '<h1>Importación terminada</h1>'
    . '<p>Productos creados: <strong>' . (int) $ok . '</strong></p>'
    . ($errores === [] ? '<p style="color:#1f4d3a">Sin errores.</p>' : '<h2 style="font-size:16px">Filas con problemas</h2><ul>' . $lista . '</ul>')
    . '<p style="margin-top:20px"><a href="/mayorista/productos" style="color:#1f4d3a">Volver al catálogo</a></p>';

require __DIR__ . '/../layout.php';
