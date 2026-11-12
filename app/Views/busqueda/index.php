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
    $dist = number_format((float) $r['distancia_km'], 1);
    $stock = (int) $r['stock_disponible'];
    $minimo = (int) $r['cantidad_minima'];
    $paso = max(1, (int) $r['multiplo_venta']);

    $fichas .= '<article class="ficha" data-i="' . $i . '" data-lat="' . $e((string) $r['mayorista']['latitud']) . '" data-lng="' . $e((string) $r['mayorista']['longitud']) . '">'
        . '<div class="ficha__izq">'
        . '<h3>' . $e($r['nombre']) . '</h3>'
        . '<p class="ficha__prov">' . $e($r['mayorista']['nombre'])
        . ' <span class="badge">' . $e($r['categoria']) . '</span>'
        . '<a class="ficha__ver" href="/mayoristas/' . $e($r['mayorista']['uuid']) . '">Ver mayorista</a></p>'
        . '<div class="ficha__precio">$' . number_format((float) $r['precio_base'], 2)
        . '<span>+ IVA · ' . $e($r['unidad_venta']) . ' de ' . $e($r['contenido']) . '</span></div>'
        . '</div>'
        . '<div class="ficha__der">'
        . '<span class="ficha__dist">' . $dist . ' km</span>'
        . '<span class="ficha__stock">' . $stock . ' disponibles</span>';

    if ($puedeComprar) {
        $fichas .= '<form method="post" action="/carrito/agregar" class="ficha__compra">'
            . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
            . '<input type="hidden" name="producto" value="' . $e($r['uuid']) . '">'
            . '<input type="hidden" name="volver" value="/buscar">'
            . '<div class="stepper">'
            . '<button type="button" class="stepper__menos" aria-label="Quitar uno">&minus;</button>'
            . '<input type="number" name="cantidad" value="' . $minimo . '" min="' . $minimo . '" step="' . $paso . '" max="' . $stock . '" aria-label="Cantidad">'
            . '<button type="button" class="stepper__mas" aria-label="Agregar uno">+</button>'
            . '</div>'
            . '<button type="submit" class="btn-agregar">Agregar</button>'
            . '</form>';
    }

    $fichas .= '</div></article>';
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
$anchoCompleto = true;

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
    . '<div class="buscador">'
    . '<div class="buscador__mapa"><div id="mapa" class="mapa"></div></div>'
    . '<div class="buscador__resultados">' . $fichas
    . ($paginacion !== '' ? '<div class="paginacion">' . $paginacion . '</div>' : '')
    . '</div></div>';

$estilos = <<<'CSS'
.filtros {
    display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:20px 0; width:100%;
    background:#fff; border:1px solid #E5E7EB; border-radius:14px; padding:12px;
}
.filtros input,
.filtros select {
    height:44px; padding:0 14px; font-size:14px; color:#111827; background:#fff;
    border:1px solid #E5E7EB; border-radius:10px;
    transition:border-color .18s ease, box-shadow .18s ease;
}
.filtros input::placeholder { color:#9ca3af; }
.filtros input:focus,
.filtros select:focus {
    outline:none; border-color:#1f4d3a; box-shadow:0 0 0 3px rgba(31,77,58,.10);
}
.filtros select {
    appearance:none; -webkit-appearance:none; -moz-appearance:none; cursor:pointer;
    padding-right:38px; background-repeat:no-repeat; background-position:right 14px center;
    background-image:url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}
.filtros button {
    height:44px; padding:0 22px; font-size:14px; font-weight:500; color:#fff;
    background:#1f4d3a; border:0; border-radius:10px; cursor:pointer; white-space:nowrap;
    transition:background .18s ease, transform .1s ease;
}
.filtros button:hover { background:#17392b; }
.filtros button:active { transform:translateY(1px); }

/* Dos columnas solo en computadora */
.buscador { display:grid; grid-template-columns:1fr; gap:18px; align-items:start; width:100%; }
.buscador__mapa { position:relative; }
.mapa {
    height:340px; width:100%; border-radius:14px; overflow:hidden;
    box-shadow:0 4px 18px rgba(17,24,39,.07); border:1px solid #E5E7EB; z-index:1;
}
@media (min-width:992px) {
    /* 2/3 y 1/3, pero la columna de fichas nunca baja de 360 px: en monitores
       muy anchos el mapa se queda con el espacio sobrante. */
    .buscador { grid-template-columns: minmax(0, 2fr) minmax(360px, 1fr); gap:22px; }
    .buscador__mapa { position:sticky; top:76px; }
    .mapa { height:calc(100vh - 150px); min-height:520px; }
    .buscador__resultados {
        max-height:calc(100vh - 150px); overflow-y:auto; padding-right:6px;
        container-type:inline-size;
    }
    .buscador__resultados::-webkit-scrollbar { width:8px; }
    .buscador__resultados::-webkit-scrollbar-thumb { background:#d9d7d0; border-radius:8px; }
    .buscador__resultados::-webkit-scrollbar-track { background:transparent; }
}
.fichas { display:grid; grid-template-columns:1fr; gap:12px; width:100%; }
.ficha {
    display:flex; justify-content:space-between; align-items:flex-start; gap:24px;
    background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:16px 20px;
    cursor:pointer; transition:border-color .15s, box-shadow .15s, transform .15s;
}
.ficha:hover { border-color:#c9d6cd; box-shadow:0 2px 10px rgba(17,24,39,.05); }
.ficha.activa { border-color:#1f4d3a; box-shadow:0 0 0 3px rgba(31,77,58,.09); }
.ficha__izq { flex:1; min-width:0; }
.ficha h3 { font-size:18px; font-weight:600; letter-spacing:-.01em; margin:0 0 4px; }
.ficha__prov { font-size:13px; color:#6b7280; margin:0 0 10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.badge {
    font-size:11px; font-weight:500; color:#6b7280; background:#f3f4f6;
    padding:2px 9px; border-radius:999px; letter-spacing:0; white-space:nowrap;
}
.ficha__precio { font-size:22px; font-weight:700; letter-spacing:-.02em; color:#111827; }
.ficha__precio span { font-size:12px; font-weight:400; color:#9ca3af; letter-spacing:0; margin-left:6px; }
.ficha__der { display:flex; flex-direction:column; align-items:flex-end; gap:8px; flex-shrink:0; }
.ficha__dist { font-size:12px; color:#9ca3af; }
.ficha__stock {
    font-size:12px; font-weight:500; color:#1f4d3a; background:#eaf1ec;
    padding:3px 10px; border-radius:999px; white-space:nowrap;
}
.ficha__compra { display:flex; align-items:center; gap:8px; margin:2px 0 0; }
.stepper { display:flex; align-items:center; border:1px solid #E5E7EB; border-radius:8px; overflow:hidden; }
.stepper button {
    width:30px; height:34px; padding:0; background:#fff; border:0; color:#4b5563;
    font-size:16px; line-height:1; cursor:pointer;
}
.stepper button:hover { background:#f9fafb; }
.stepper input {
    width:46px; height:34px; padding:0; border:0; border-left:1px solid #E5E7EB; border-right:1px solid #E5E7EB;
    text-align:center; font-size:14px; border-radius:0;
}
.stepper input:focus { outline:none; box-shadow:none; }
.stepper input::-webkit-outer-spin-button,
.stepper input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.btn-agregar { height:34px; padding:0 16px; font-size:13px; font-weight:500; background:#1f4d3a; color:#fff;
               border:0; border-radius:8px; cursor:pointer; white-space:nowrap; }
.btn-agregar:hover { background:#17392b; }
.ficha__ver { font-size:12px; color:#6b7280; text-decoration:none; border-bottom:1px solid #e5e7eb; margin-left:2px; }
.ficha__ver:hover { color:#1f4d3a; border-bottom-color:#1f4d3a; }
/* Cuando la columna de resultados es angosta, la ficha se acomoda sola:
   se consulta el ancho del contenedor, no el de la ventana. */
@container (max-width: 520px) {
    .ficha { flex-direction:column; gap:12px; }
    .ficha__der {
        flex-direction:row; align-items:center; justify-content:flex-start;
        flex-wrap:wrap; width:100%; gap:10px;
    }
    .ficha__compra { margin-left:auto; }
}
@media (max-width:560px) {
    .ficha { flex-direction:column; gap:14px; padding:14px 16px; }
    .ficha__der { align-items:flex-start; width:100%; }
    .ficha__compra { width:100%; margin-left:0; }
}
.paginacion { display:flex; gap:12px; align-items:center; justify-content:center; margin-top:28px; }
CSS;

$scripts = <<<JS
const datos = {$json};
const mapa = L.map('mapa', { scrollWheelZoom: false }).setView([{$lat}, {$lng}], 12);
window.mapa = mapa; // expuesto para depuración
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

// Botones − y + del selector de cantidad.
document.querySelectorAll('.stepper').forEach(st => {
    const inp = st.querySelector('input');
    const paso = parseInt(inp.step, 10) || 1;
    const min = parseInt(inp.min, 10) || 1;
    const max = parseInt(inp.max, 10) || 9999;

    st.querySelector('.stepper__menos').addEventListener('click', () => {
        const v = (parseInt(inp.value, 10) || min) - paso;
        inp.value = Math.max(min, v);
    });

    st.querySelector('.stepper__mas').addEventListener('click', () => {
        const v = (parseInt(inp.value, 10) || min) + paso;
        inp.value = Math.min(max, v);
    });
});

// El formulario de compra no debe centrar el mapa al hacer clic.
document.querySelectorAll('.ficha__compra, .ficha__ver').forEach(el => {
    el.addEventListener('click', e => e.stopPropagation());
});

// Los desplegables aplican el filtro al cambiar. Se hace con listeners y no
// con onchange en el HTML porque el CSP no permite manejadores en línea.
document.querySelectorAll('.filtros select').forEach(sel => {
    sel.addEventListener('change', () => document.querySelector('.filtros').submit());
});

// Al mover el mapa se actualizan las coordenadas y se repite la búsqueda,
// pero solo si el centro se desplazó de verdad. Hacer zoom no debe recargar
// la página: era lo que hacía sentir lento el mapa.
let temporizador = null;
let centroPrevio = mapa.getCenter();

function distanciaKm(a, b) {
    const R = 6371, rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad;
    const dLng = (b.lng - a.lng) * rad;
    const h = Math.sin(dLat / 2) ** 2 +
              Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

mapa.on('moveend', () => {
    const c = mapa.getCenter();
    const movido = distanciaKm(centroPrevio, c);

    document.getElementById('f-lat').value = c.lat.toFixed(6);
    document.getElementById('f-lng').value = c.lng.toFixed(6);

    // Menos de 300 m es un ajuste de zoom o un temblor: no se recarga.
    if (movido < 0.3) {
        return;
    }

    centroPrevio = c;
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        document.querySelector('.filtros').submit();
    }, 600);
});
JS;

require __DIR__ . '/../layout.php';
