<?php
/** @var callable $e */
/** @var string $nonce */
/** @var string $csrf */
/** @var array|null $data */
/** @var string|null $error */
/** @var array $filtros */
/** @var float $lat */
/** @var float $lng */
/** @var array $categorias */

$resultados = $data['resultados'] ?? [];
$total = $data['total'] ?? 0;
$paginas = $data['paginas'] ?? 0;
$page = $data['page'] ?? 1;

$opcionesCategoria = '<option value="">Todas las categorías</option>';
foreach ($categorias as $c) {
    $sel = ((int) ($_GET['categoria_id'] ?? 0) === (int) $c['id']) ? ' selected' : '';
    $opcionesCategoria .= '<option value="' . (int) $c['id'] . '"' . $sel . '>' . $e($c['nombre']) . '</option>';
}

$opcionesOrden = '';
foreach (['distancia' => 'Más cercano', 'precio_asc' => 'Menor precio', 'precio_desc' => 'Mayor precio', 'reputacion' => 'Mejor calificado'] as $valor => $texto) {
    $sel = $filtros['orden'] === $valor ? ' selected' : '';
    $opcionesOrden .= '<option value="' . $valor . '"' . $sel . '>' . $e($texto) . '</option>';
}

$puedeComprar = \App\Core\Session::obtener('user_id', null) !== null
    && in_array('comercio', (array) \App\Core\Session::obtener('roles', []), true);

$fichas = '';
foreach ($resultados as $i => $r) {
    $fichas .= '<article class="ficha" data-i="' . $i . '" data-lat="' . $e((string) $r['mayorista']['latitud']) . '" data-lng="' . $e((string) $r['mayorista']['longitud']) . '">'
        . '<div class="ficha__cab"><h3>' . $e($r['nombre']) . '</h3>'
        . '<span class="ficha__dist">' . number_format((float) $r['distancia_km'], 1) . ' km</span></div>'
        . '<p class="ficha__mayo">' . $e($r['mayorista']['nombre']) . ' · ' . $e($r['categoria']) . '</p>'
        . '<div class="ficha__precio">$' . number_format((float) $r['precio_base'], 2)
        . ' <span>+ IVA · ' . $e($r['unidad_venta']) . ' de ' . $e($r['contenido']) . '</span></div>'
        . '<div class="ficha__pie">'
        . '<span>' . (int) $r['stock_disponible'] . ' disponibles</span>'
        . '<a href="/mayoristas/' . $e($r['mayorista']['uuid']) . '">Ver mayorista</a>'
        . '</div>'
        . ($puedeComprar
            ? '<form method="post" action="/carrito/agregar" class="ficha__agregar">'
              . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
              . '<input type="hidden" name="producto" value="' . $e($r['uuid']) . '">'
              . '<input type="hidden" name="volver" value="/buscar">'
              . '<input type="number" name="cantidad" value="' . (int) $r['cantidad_minima'] . '" min="' . (int) $r['cantidad_minima'] . '" step="' . (int) $r['multiplo_venta'] . '" max="' . (int) $r['stock_disponible'] . '">'
              . '<button type="submit">Agregar</button></form>'
            : '')
        . '</article>';
}

if ($fichas === '') {
    $fichas = '<p class="vacio">No hay productos en este radio. Prueba ampliando la distancia o cambiando el texto.</p>';
}

$paginacion = '';
if ($paginas > 1) {
    $base = '/buscar?' . http_build_query([
        'q' => $filtros['q'], 'radio' => $filtros['radio'], 'orden' => $filtros['orden'],
        'lat' => $lat, 'lng' => $lng,
    ]);
    if ($page > 1) {
        $paginacion .= '<a class="boton boton--claro" href="' . $e($base . '&page=' . ($page - 1)) . '">Anterior</a>';
    }
    $paginacion .= '<span style="font-size:14px;color:#6b7280">Página ' . (int) $page . ' de ' . (int) $paginas . '</span>';
    if ($page < $paginas) {
        $paginacion .= '<a class="boton boton--claro" href="' . $e($base . '&page=' . ($page + 1)) . '">Siguiente</a>';
    }
}

$json = json_encode(array_map(static fn (array $r): array => [
    'nombre'   => $r['nombre'],
    'lat'      => $r['mayorista']['latitud'],
    'lng'      => $r['mayorista']['longitud'],
    'precio'   => $r['precio_base'],
    'dist'     => $r['distancia_km'],
    'mayo'     => $r['mayorista']['nombre'],
], $resultados), JSON_UNESCAPED_UNICODE) ?: '[]';

$mapa = true;

$contenido = '<h1>Buscar productos</h1>'
    . '<p>' . (int) $total . ' producto' . ($total === 1 ? '' : 's') . ' cerca de ti.</p>'
    . ($error !== null ? '<div class="aviso aviso--error">' . $e($error) . '</div>' : '')
    . '<form method="get" action="/buscar" class="filtros">'
    . '<input type="hidden" name="lat" id="f-lat" value="' . $e((string) $lat) . '">'
    . '<input type="hidden" name="lng" id="f-lng" value="' . $e((string) $lng) . '">'
    . '<input name="q" value="' . $e($filtros['q']) . '" placeholder="¿Qué buscas? café, cloro, tornillo…" style="flex:2;min-width:200px">'
    . '<select name="categoria_id" style="flex:1;min-width:150px">' . $opcionesCategoria . '</select>'
    . '<select name="radio" style="flex:0 0 130px">'
    . '<option value="2"' . ($filtros['radio'] == 2 ? ' selected' : '') . '>2 km</option>'
    . '<option value="5"' . ($filtros['radio'] == 5 ? ' selected' : '') . '>5 km</option>'
    . '<option value="10"' . ($filtros['radio'] == 10 ? ' selected' : '') . '>10 km</option>'
    . '<option value="20"' . ($filtros['radio'] == 20 ? ' selected' : '') . '>20 km</option>'
    . '<option value="30"' . ($filtros['radio'] == 30 ? ' selected' : '') . '>30 km</option>'
    . '</select>'
    . '<select name="orden" style="flex:0 0 160px">' . $opcionesOrden . '</select>'
    . '<button type="submit">Buscar</button>'
    . '</form>'
    . '<div id="mapa" class="mapa"></div>'
    . '<div class="fichas">' . $fichas . '</div>'
    . ($paginacion !== '' ? '<div class="paginacion">' . $paginacion . '</div>' : '');

$estilos = <<<'CSS'
.filtros { display:flex; gap:10px; flex-wrap:wrap; margin:20px 0; align-items:center; }
.filtros input, .filtros select { height:42px; }
.mapa { height:380px; border-radius:12px; border:1px solid #e8e6df; margin-bottom:22px; z-index:1; }
.fichas { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
.ficha { background:#fff; border:1px solid #e8e6df; border-radius:12px; padding:16px; cursor:pointer;
         transition:border-color .15s, box-shadow .15s; }
.ficha:hover { border-color:#c9d6cd; }
.ficha.activa { border-color:#1f4d3a; box-shadow:0 0 0 3px rgba(31,77,58,.1); }
.ficha__cab { display:flex; justify-content:space-between; gap:10px; align-items:baseline; }
.ficha h3 { font-size:15px; margin:0; font-weight:600; letter-spacing:-.01em; }
.ficha__dist { font-size:12px; color:#6b7280; white-space:nowrap; }
.ficha__mayo { font-size:13px; color:#6b7280; margin:4px 0 10px; }
.ficha__precio { font-size:19px; font-weight:600; letter-spacing:-.02em; }
.ficha__precio span { font-size:12px; font-weight:400; color:#9ca3af; }
.ficha__pie { display:flex; justify-content:space-between; align-items:center; margin-top:12px;
              font-size:13px; color:#6b7280; border-top:1px solid #f4f2ec; padding-top:10px; }
.ficha__agregar { display:flex; gap:8px; margin-top:10px; }
.ficha__agregar input { width:70px; height:36px; padding:0 8px; }
.ficha__agregar button { height:36px; padding:0 14px; font-size:13px; }
.paginacion { display:flex; gap:12px; align-items:center; justify-content:center; margin-top:28px; }
CSS;

$scripts = <<<JS
const datos = {$json};
const mapa = L.map('mapa', { scrollWheelZoom: false }).setView([{$lat}, {$lng}], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '© OpenStreetMap'
}).addTo(mapa);

const marcadores = [];
datos.forEach((d, i) => {
    const m = L.marker([d.lat, d.lng]).addTo(mapa);
    m.bindPopup('<strong>' + d.nombre + '</strong><br>' + d.mayo + '<br>$' + d.precio + ' · ' + d.dist + ' km');
    m.on('click', () => resaltar(i, false));
    marcadores.push(m);
});

function resaltar(i, centrar) {
    document.querySelectorAll('.ficha').forEach(f => f.classList.remove('activa'));
    const ficha = document.querySelector('.ficha[data-i="' + i + '"]');
    if (ficha) {
        ficha.classList.add('activa');
        if (centrar) {
            ficha.scrollIntoView({ behavior: 'smooth', block: 'center' });
            mapa.setView([parseFloat(ficha.dataset.lat), parseFloat(ficha.dataset.lng)], 14);
        }
    }
    if (marcadores[i]) marcadores[i].openPopup();
}

document.querySelectorAll('.ficha').forEach(f => {
    f.addEventListener('click', () => resaltar(parseInt(f.dataset.i, 10), true));
});

// Al mover el mapa se actualizan las coordenadas y se repite la búsqueda.
let temporizador = null;
mapa.on('moveend', () => {
    const c = mapa.getCenter();
    document.getElementById('f-lat').value = c.lat.toFixed(6);
    document.getElementById('f-lng').value = c.lng.toFixed(6);
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        document.querySelector('.filtros').submit();
    }, 500);
});
JS;

require __DIR__ . '/../layout.php';
