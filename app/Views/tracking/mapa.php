<?php
/** @var callable $e */
/** @var array $pedido */
/** @var bool $disponible */
/** @var string|null $motivo */
/** @var array|null $datosInicial */
$mapa = true;
$estilos = '
    .mapa { height: 60vh; min-height: 380px; width: 100%; border-radius: 14px;
            overflow: hidden; border: 1px solid #E5E7EB;
            box-shadow: 0 4px 18px rgba(17,24,39,.07); z-index: 1; }
    @media (max-width: 560px) { .mapa { height: 46vh; min-height: 300px; } }
';
$idPedido = $e((string) $pedido['uuid']);

$contenido = '<h1>Seguimiento del pedido</h1>'
    . '<p>' . $e($pedido['mayorista']) . ' · pedido ' . $e(substr((string) $pedido['uuid'], 0, 8)) . '</p>';

if (!$disponible) {
    $contenido .= '<div class="aviso aviso--alerta">' . $e((string) $motivo) . '</div>'
        . '<p><a href="/pedidos/' . $idPedido . '">Volver al pedido</a></p>';
} else {
    $contenido .= '<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin:16px 0;padding:14px 18px;background:#fff;border:1px solid #E5E7EB;border-radius:12px">'
        . '<div><div style="font-size:12px;color:#9ca3af">Llega en</div>'
        . '<div id="eta" style="font-size:24px;font-weight:700;line-height:1.2">—</div></div>'
        . '<div><div style="font-size:12px;color:#9ca3af">Distancia</div>'
        . '<div id="dist" style="font-size:16px;font-weight:500">—</div></div>'
        . '<div><div style="font-size:12px;color:#9ca3af">Estado</div>'
        . '<div id="estado" style="font-size:16px;font-weight:500">' . $e((string) $pedido['estado']) . '</div></div>'
        . '<div style="margin-left:auto;font-size:12px;color:#9ca3af">Se actualiza cada 15 s</div>'
        . '</div>'
        . '<div id="mapa" class="mapa"></div>'
        . '<p style="margin-top:16px"><a href="/pedidos/' . $idPedido . '">‹ Volver al pedido</a></p>';
}

$icono = <<<'JS'
const iconoRepartidor = L.divIcon({
    className: '',
    html: '<div style="width:34px;height:34px;border-radius:50%;background:#1f4d3a;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;font-size:16px">🚚</div>',
    iconSize: [34, 34], iconAnchor: [17, 17]
});
const iconoDestino = L.divIcon({
    className: '',
    html: '<div style="width:30px;height:30px;border-radius:50% 50% 50% 0;background:#b91c1c;border:3px solid #fff;transform:rotate(-45deg);box-shadow:0 2px 8px rgba(0,0,0,.25)"></div>',
    iconSize: [30, 30], iconAnchor: [15, 30]
});
JS;

$datos = json_encode($datosInicial, JSON_UNESCAPED_UNICODE) ?: 'null';

$scripts = $icono . <<<JS
const inicial = {$datos};
const pedido = "{$idPedido}";

const mapa = L.map('mapa', { scrollWheelZoom: true });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);

let marcadorRep = null;
let linea = null;
let etag = null;

function pintar(d) {
    if (!d) return;

    const destino = [d.destino.lat, d.destino.lng];
    L.marker(destino, { icon: iconoDestino }).addTo(mapa).bindPopup('Entrega: ' + d.destino.calle);

    if (d.repartidor) {
        const pos = [d.repartidor.lat, d.repartidor.lng];
        if (marcadorRep) { marcadorRep.setLatLng(pos); } else { marcadorRep = L.marker(pos, { icon: iconoRepartidor }).addTo(mapa); }

        if (linea) { linea.setLatLngs(d.ruta); } else { linea = L.polyline(d.ruta, { color: '#1f4d3a', weight: 4, opacity: .7 }).addTo(mapa); }

        mapa.fitBounds(L.latLngBounds([pos, destino]).pad(0.25));
    } else {
        mapa.setView(destino, 14);
    }

    document.getElementById('eta').textContent = d.eta_min ? d.eta_min + ' min' : '—';
    document.getElementById('dist').textContent = d.distancia_km !== null ? d.distancia_km + ' km' : '—';
    document.getElementById('estado').textContent = d.estado.replace(/_/g, ' ');
}

pintar(inicial);

async function refrescar() {
    try {
        const cabeceras = etag ? { 'If-None-Match': etag } : {};
        const r = await fetch('/pedidos/' + pedido + '/tracking', { headers: cabeceras, credentials: 'same-origin' });

        if (r.status === 304) return; // sin cambios: no se redibuja
        if (!r.ok) return;

        const nuevo = r.headers.get('ETag');
        if (nuevo) etag = nuevo;

        const j = await r.json();
        if (j.ok) pintar(j.data);
    } catch (e) { /* se reintenta en el siguiente ciclo */ }
}

setInterval(refrescar, 15000);
JS;

require __DIR__ . '/../layout.php';
