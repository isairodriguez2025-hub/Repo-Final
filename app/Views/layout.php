<?php
/**
 * Plantilla base. Todo lo que se imprime pasa por $e() para escapar.
 *
 * @var callable $e
 * @var string   $nonce
 * @var string   $titulo
 * @var string   $contenido
 */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($titulo) ?> · Didi2</title>
    <style nonce="<?= $e($nonce) ?>">
        :root { --tinta:#1a1a1a; --gris:#6b7280; --linea:#e6e4dd; --marca:#1f4d3a; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
               background:#fafaf7; color:var(--tinta); line-height:1.6; }
        main { max-width: 720px; margin: 0 auto; padding: 48px 24px; }
        h1 { font-size: 26px; letter-spacing: -.02em; margin: 0 0 8px; }
        p { color: var(--gris); }
        code { background:#f2f1ec; padding:2px 6px; border-radius:4px; font-size:13px; }
    </style>
</head>
<body>
<main>
    <?= $contenido ?>
</main>
</body>
</html>
