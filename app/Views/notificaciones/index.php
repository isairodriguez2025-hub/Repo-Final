<?php
/** @var callable $e */
/** @var array $avisos */
$filas = '';
foreach ($avisos as $n) {
    $nuevo = $n['estado'] !== 'leido';
    $filas .= '<article style="background:#fff;border:1px solid ' . ($nuevo ? '#cfdcd3' : '#E5E7EB') . ';border-radius:10px;padding:14px 16px;margin-bottom:10px">'
        . '<div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline">'
        . '<strong style="font-size:14px">' . $e($n['asunto']) . '</strong>'
        . '<span style="font-size:12px;color:#9ca3af;white-space:nowrap">' . $e(substr((string) $n['created_at'], 0, 16)) . '</span></div>'
        . '<div style="font-size:14px;color:#4b5563;margin-top:6px">' . strip_tags((string) $n['cuerpo'], '<br><em><strong>') . '</div>'
        . ($n['enlace'] ? '<a href="' . $e($n['enlace']) . '" style="font-size:13px;display:inline-block;margin-top:8px">Ver detalle</a>' : '')
        . '</article>';
}

if ($filas === '') {
    $filas = '<p class="vacio">No tienes avisos todavía.</p>';
}

$contenido = '<h1>Avisos</h1><div style="margin-top:18px;max-width:760px">' . $filas . '</div>';

require __DIR__ . '/../layout.php';
