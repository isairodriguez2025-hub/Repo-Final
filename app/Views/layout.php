<?php
/**
 * Plantilla base. Todo lo que se imprime pasa por $e() para escapar.
 *
 * @var callable $e
 * @var string   $nonce
 * @var string   $titulo
 * @var string   $contenido
 */
$usuario = \App\Core\Session::obtener('user_id', null);
$roles = \App\Core\Session::obtener('roles', []);
$rol = is_array($roles) && $roles !== [] ? (string) $roles[0] : null;

$enlaces = [];
if ($usuario !== null) {
    $enlaces[] = ['/buscar', 'Buscar'];

    if ($rol === 'comercio') {
        $enlaces[] = ['/pedidos', 'Mis pedidos'];
        $enlaces[] = ['/favoritos', 'Favoritos'];
        $enlaces[] = ['/programados', 'Programados'];
    }

    if ($rol === 'mayorista') {
        $enlaces[] = ['/mayorista/productos', 'Mis productos'];
        $enlaces[] = ['/mayorista/pedidos', 'Pedidos'];
    }

    $enlaces[] = ['/finanzas', 'Finanzas'];
    $enlaces[] = ['/facturacion', 'Facturación'];
    $enlaces[] = ['/notificaciones', 'Avisos'];
    $enlaces[] = ['/perfil', 'Mi perfil'];
    if (in_array($rol, ['admin_total', 'admin_kyc'], true)) {
        $enlaces[] = ['/admin/kyc', 'Verificaciones'];
    }
}

$nav = '';
foreach ($enlaces as [$href, $texto]) {
    $activo = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), $href) ? ' class="activo"' : '';
    $nav .= '<a href="' . $e($href) . '"' . $activo . '>' . $e($texto) . '</a>';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($titulo) ?> · Didi2</title>
    <?php if (!empty($mapa)): ?>
        <link rel="stylesheet" href="/assets/leaflet/leaflet.css">
    <?php endif; ?>
    <style nonce="<?= $e($nonce) ?>">
        :root {
            --tinta: #1a1a1a;
            --gris: #6b7280;
            --gris-claro: #9ca3af;
            --linea: #e8e6df;
            --fondo: #fafaf7;
            --marca: #1f4d3a;
            --marca-suave: #eaf1ec;
            --alerta: #a56a12;
            --error: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: var(--fondo);
            color: var(--tinta);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        header.barra {
            background: #fff;
            border-bottom: 1px solid var(--linea);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .barra__interior {
            max-width: 960px;
            margin: 0 auto;
            padding: 0 24px;
            height: 60px;
            display: flex;
            align-items: center;
            gap: 28px;
        }
        .marca {
            font-weight: 650;
            font-size: 17px;
            letter-spacing: -.02em;
            color: var(--marca);
            text-decoration: none;
        }
        .marca span { color: var(--gris-claro); font-weight: 400; }
        nav { display: flex; gap: 20px; flex: 1; }
        nav a {
            color: var(--gris);
            text-decoration: none;
            font-size: 14px;
            padding: 4px 0;
            border-bottom: 2px solid transparent;
        }
        nav a:hover { color: var(--tinta); }
        nav a.activo { color: var(--marca); border-bottom-color: var(--marca); }
        .salir {
            font-size: 14px;
            color: var(--gris);
            text-decoration: none;
        }
        .salir:hover { color: var(--error); }
        main {
            max-width: 960px;
            margin: 0 auto;
            padding: 40px 24px 72px;
        }
        /* Pantallas que necesitan todo el ancho (buscador con mapa). */
        .ancho-completo .barra__interior { max-width: 100%; padding: 0 24px; }
        .ancho-completo main { max-width: 100%; padding: 24px 24px 48px; }
        h1 {
            font-size: 26px;
            letter-spacing: -.02em;
            margin: 0 0 6px;
            font-weight: 650;
        }
        h2 {
            font-size: 17px;
            letter-spacing: -.01em;
            margin: 32px 0 10px;
            font-weight: 600;
        }
        p { color: var(--gris); margin: 0 0 12px; }
        a { color: var(--marca); }
        code {
            background: #f2f1ec;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 13px;
        }
        /* Formularios */
        input, select, textarea {
            font: inherit;
            color: var(--tinta);
            background: #fff;
            border: 1px solid var(--linea);
            border-radius: 8px;
            padding: 9px 12px;
            width: 100%;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--marca);
            box-shadow: 0 0 0 3px rgba(31, 77, 58, .1);
        }
        input[type=checkbox] { width: auto; accent-color: var(--marca); }
        label { display: block; margin-bottom: 12px; }
        label > span {
            display: block;
            font-size: 13px;
            color: var(--gris);
            margin-bottom: 4px;
        }
        button, .boton {
            font: inherit;
            font-size: 14px;
            font-weight: 500;
            background: var(--marca);
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 10px 18px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        button:hover, .boton:hover { background: #17392b; }
        .boton--claro {
            background: #fff;
            color: var(--tinta);
            border: 1px solid var(--linea);
        }
        .boton--claro:hover { background: #f7f6f2; }
        /* Tablas */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: #fff;
            border: 1px solid var(--linea);
            border-radius: 10px;
            overflow: hidden;
        }
        thead th {
            text-align: left;
            font-weight: 500;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--gris-claro);
            padding: 10px 14px;
            border-bottom: 1px solid var(--linea);
            background: #fcfcfa;
        }
        tbody td { padding: 12px 14px; border-bottom: 1px solid #f4f2ec; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: #fcfcfa; }
        /* Avisos */
        .aviso {
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin: 0 0 16px;
        }
        .aviso--ok { background: var(--marca-suave); border: 1px solid #cfdcd3; color: #1f4d3a; }
        .aviso--alerta { background: #fdf6e6; border: 1px solid #efe0bd; color: #7a5a12; }
        .aviso--error { background: #fef2f2; border: 1px solid #f6d5d5; color: var(--error); }
        .error-campo { font-size: 12px; color: var(--error); display: block; margin-top: 3px; }
        .vacio { color: var(--gris-claro); padding: 20px 0; }
        <?= $estilos ?? '' ?>
        /* --- Selector de cantidad (compartido) --------------------------- */
        .stepper { display:inline-flex; align-items:center; border:1px solid #E5E7EB; border-radius:8px; overflow:hidden; background:#fff; }
        .stepper button {
            width:32px; height:36px; padding:0; background:#fff; border:0; color:#4b5563;
            font-size:16px; line-height:1; cursor:pointer;
            transition: background .12s ease, color .12s ease;
        }
        .stepper button:hover { background:#f3f4f6; color:#111827; }
        .stepper button:active { background:#e5e7eb; }
        .stepper input {
            width:52px; height:36px; padding:0; border:0; border-left:1px solid #E5E7EB; border-right:1px solid #E5E7EB;
            text-align:center; font-size:14px; border-radius:0; appearance:textfield; -moz-appearance:textfield;
        }
        .stepper input:focus { outline:none; box-shadow:none; }
        .stepper input::-webkit-outer-spin-button,
        .stepper input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }

        /* --- Botón flotante del carrito ---------------------------------- */
        .fab-carrito {
            position: fixed; right: 24px; bottom: 24px; z-index: 90;
            width: 58px; height: 58px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #1f4d3a; color: #fff; text-decoration: none;
            box-shadow: 0 8px 24px rgba(17,24,39,.22), 0 2px 6px rgba(17,24,39,.12);
            transition: transform .15s ease, background .15s ease, box-shadow .15s ease;
        }
        .fab-carrito:hover { background:#17392b; transform: translateY(-2px); box-shadow: 0 12px 28px rgba(17,24,39,.26); }
        .fab-carrito:active { transform: translateY(0); }
        .fab-carrito__badge {
            position: absolute; top: -4px; right: -4px; min-width: 22px; height: 22px; padding: 0 6px;
            border-radius: 999px; background: #b91c1c; color: #fff;
            font-size: 12px; font-weight: 600; line-height: 22px; text-align: center;
            border: 2px solid #fafaf7;
        }
        @media (max-width: 640px) {
            .fab-carrito { right: 16px; bottom: 16px; width: 54px; height: 54px; }
        }

        @media (max-width: 640px) {
            .barra__interior { gap: 14px; padding: 0 16px; height: 56px; }
            /* En móvil la marca se queda en "Didi2" y el menú se desliza. */
            .marca span { display: none; }
            nav {
                gap: 16px; overflow-x: auto; white-space: nowrap;
                scrollbar-width: none; -ms-overflow-style: none;
            }
            nav::-webkit-scrollbar { display: none; }
            nav a { flex: 0 0 auto; }
            main { padding: 24px 16px 48px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body class="<?= !empty($anchoCompleto) ? 'ancho-completo' : '' ?>">
<header class="barra">
    <div class="barra__interior">
        <a class="marca" href="<?= $usuario !== null ? '/perfil' : '/login' ?>">Didi2 <span>· B2B</span></a>
        <nav><?= $nav ?></nav>
        <?php if ($usuario !== null): ?>
            <form method="post" action="/logout" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= $e(\App\Core\Csrf::token()) ?>">
                <button type="submit" class="salir" style="background:none;border:0;padding:0;cursor:pointer">Salir</button>
            </form>
        <?php endif; ?>
    </div>
</header>
<main>
    <?= $contenido ?>
</main>
<?php if (!empty($mapa)): ?>
    <script src="/assets/leaflet/leaflet.js"></script>
<?php endif; ?>
<?php
$rolActual = is_array($roles) && $roles !== [] ? (string) $roles[0] : null;
$enCarrito = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/carrito');
?>
<?php if ($usuario !== null && $rolActual === 'comercio' && !$enCarrito): ?>
    <?php $articulos = \App\Modules\Carrito\CarritoContador::paraUsuario((int) $usuario); ?>
    <a class="fab-carrito" href="/carrito" aria-label="Ir al carrito<?= $articulos > 0 ? ' (' . $articulos . ' artículos)' : '' ?>">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
        </svg>
        <?php if ($articulos > 0): ?>
            <span class="fab-carrito__badge"><?= $articulos > 99 ? '99+' : $articulos ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>
<?php if (!empty($scripts)): ?>
    <script nonce="<?= $e($nonce) ?>"><?= $scripts ?></script>
<?php endif; ?>
</body>
</html>
