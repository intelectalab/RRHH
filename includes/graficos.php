<?php
/**
 * graficos.php — Gráficos en SVG generados en el servidor (sin librerías externas).
 * Reglas: un solo eje, barras de máximo 24 px con extremo redondeado, líneas de 2 px,
 * cuadrícula tenue, etiquetas selectivas y tooltip en cada marca (atributo data-tip).
 */

/** Valor redondo para el tope del eje (1, 2, 5, 10, 20, 50…). */
function tope_eje(float $max): float {
    if ($max <= 0) return 1;
    $p = 10 ** floor(log10($max)); foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $p >= $max) return $m * $p;
    return 10 * $p;
}
function n_eje(float $v): string { return $v >= 1000 ? num($v/1000, $v % 1000 ? 1 : 0).' mil' : (floor($v) == $v ? (string)(int)$v : num($v, 1)); }

/**
 * Columnas verticales. $datos = [['et'=>'L 14', 'v'=>5, 'tip'=>'texto', 'clase'=>'']…]
 * $op: alto, ref (valor de referencia con línea punteada fina), ref_txt, cada (cada cuántas etiquetas mostrar), sufijo
 */
function columnas(array $datos, array $op = []): string {
    $W = 640; $H = $op['alto'] ?? 180; $ml = 28; $mb = 22; $mt = 10; $n = max(count($datos), 1);
    $max = tope_eje(max(array_merge([1], array_column($datos, 'v'), [($op['ref'] ?? 0)])));
    $ph = $H - $mb - $mt; $pw = $W - $ml - 4; $band = $pw / $n; $bw = min(24, $band * .62);
    $y = fn($v) => $mt + $ph - $v / $max * $ph;
    $s = '<svg class="graf" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-label="'.e($op['titulo'] ?? 'Gráfico de columnas').'">';
    foreach ([0, .5, 1] as $k) { $yy = round($y($max*$k), 1); $s .= '<line class="g-grid" x1="'.$ml.'" x2="'.$W.'" y1="'.$yy.'" y2="'.$yy.'"/><text class="g-eje" x="'.($ml-6).'" y="'.($yy+4).'" text-anchor="end">'.n_eje($max*$k).'</text>'; }
    foreach ($datos as $i => $d) {
        $cx = $ml + $band * ($i + .5); $x0 = $cx - $bw/2; $yt = $y($d['v']); $h = $mt + $ph - $yt; $r = min(4, $h);
        if ($d['v'] > 0) $s .= '<path class="g-bar '.($d['clase'] ?? '').'" d="M'.round($x0,1).' '.($mt+$ph).'V'.round($yt+$r,1).'Q'.round($x0,1).' '.round($yt,1).' '.round($x0+$r,1).' '.round($yt,1).'H'.round($x0+$bw-$r,1).'Q'.round($x0+$bw,1).' '.round($yt,1).' '.round($x0+$bw,1).' '.round($yt+$r,1).'V'.($mt+$ph).'Z"/>';
        $s .= '<rect class="g-hit" x="'.round($cx-$band/2,1).'" y="'.$mt.'" width="'.round($band,1).'" height="'.$ph.'" data-tip="'.e($d['tip'] ?? ($d['et'].': '.$d['v'])).'" tabindex="0"/>';
        if (!isset($op['cada']) || ($i % $op['cada'] === 0 && $n-1-$i >= $op['cada']*.6) || $i === $n-1) $s .= '<text class="g-eje" x="'.round($cx,1).'" y="'.($H-6).'" text-anchor="middle">'.e($d['et']).'</text>';
    }
    if (isset($op['ref'])) { $yy = round($y($op['ref']),1); $s .= '<line class="g-ref" x1="'.$ml.'" x2="'.$W.'" y1="'.$yy.'" y2="'.$yy.'"/><text class="g-ref-t" x="'.($W-2).'" y="'.($yy-5).'" text-anchor="end">'.e($op['ref_txt'] ?? '').'</text>'; }
    return $s.'<line class="g-base" x1="'.$ml.'" x2="'.$W.'" y1="'.($mt+$ph).'" y2="'.($mt+$ph).'"/></svg>';
}

/**
 * Línea con área suave. $puntos = [['et'=>'Oct 26','v'=>63,'tip'=>'…']…]; $op: alto, min (piso del eje), escalon (true = escalones), sufijo
 */
function linea(array $puntos, array $op = []): string {
    $W = 640; $H = $op['alto'] ?? 180; $ml = $op['ml'] ?? 36; $mb = 22; $mt = 14; $mr = 10; $n = count($puntos);
    if (!$n) return '';
    $vals = array_column($puntos, 'v'); $min = $op['min'] ?? 0; $max = tope_eje(max($vals) - $min) + $min;
    if ($max <= $min) $max = $min + 1;
    $ph = $H - $mb - $mt; $pw = $W - $ml - $mr;
    $x = fn($i) => $ml + ($n === 1 ? $pw/2 : $pw * $i / ($n - 1)); $y = fn($v) => $mt + $ph - ($v - $min) / ($max - $min) * $ph;
    $s = '<svg class="graf" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-label="'.e($op['titulo'] ?? 'Gráfico de línea').'">';
    foreach ([0, .5, 1] as $k) { $v = $min + ($max-$min)*$k; $yy = round($y($v),1); $s .= '<line class="g-grid" x1="'.$ml.'" x2="'.$W.'" y1="'.$yy.'" y2="'.$yy.'"/><text class="g-eje" x="'.($ml-6).'" y="'.($yy+4).'" text-anchor="end">'.e(($op['fmt'] ?? 'n_eje')($v)).'</text>'; }
    $d = ''; foreach ($puntos as $i => $p) {
        $xx = round($x($i),1); $yy = round($y($p['v']),1);
        if (!$d) $d = "M$xx $yy"; else $d .= !empty($op['escalon']) ? ' H'.$xx.' V'.$yy : " L$xx $yy";
    }
    $s .= '<path class="g-area" d="'.$d.' V'.($mt+$ph).' H'.round($x(0),1).' Z"/><path class="g-linea" d="'.$d.'"/>';
    $band = $n > 1 ? $pw / ($n - 1) : $pw;
    foreach ($puntos as $i => $p) {
        $xx = round($x($i),1); $yy = round($y($p['v']),1);
        if (!empty($p['marca']) || $i === $n-1) $s .= '<circle class="g-punto" cx="'.$xx.'" cy="'.$yy.'" r="4"/>';
        if ($i === $n-1 && !empty($op['etiqueta_final'])) $s .= '<text class="g-val" x="'.($xx-8).'" y="'.($yy-10).'" text-anchor="end">'.e($op['etiqueta_final']).'</text>';
        $s .= '<rect class="g-hit" x="'.round($xx-$band/2,1).'" y="'.$mt.'" width="'.round($band,1).'" height="'.$ph.'" data-tip="'.e($p['tip'] ?? ($p['et'].': '.$p['v'])).'" tabindex="0"/>';
        if (!isset($op['cada']) || ($i % $op['cada'] === 0 && $n-1-$i >= $op['cada']*.6) || $i === $n-1) $s .= '<text class="g-eje" x="'.$xx.'" y="'.($H-6).'" text-anchor="'.($i === 0 ? 'start' : ($i === $n-1 ? 'end' : 'middle')).'">'.e($p['et']).'</text>';
    }
    return $s.'</svg>';
}

/** Barras horizontales en HTML (distribuciones). $items = [['et'=>'Ventas','v'=>21,'url'=>'…','tip'=>'…']…] */
function barras_h(array $items, string $sufijo = ''): string {
    $max = max(array_merge([1], array_column($items, 'v'))); $h = '<ul class="barras-h">';
    foreach ($items as $it) {
        $w = round($it['v'] / $max * 100, 1); $et = e($it['et']);
        $cont = '<span class="bh-et">'.$et.'</span><span class="bh-pista"><i style="width:'.$w.'%"></i></span><span class="bh-v">'.$it['v'].$sufijo.'</span>';
        $h .= '<li>'.(!empty($it['url']) ? '<a href="'.e($it['url']).'" data-tip="'.e($it['tip'] ?? 'Filtrar por '.$it['et']).'">'.$cont.'</a>' : '<div data-tip="'.e($it['tip'] ?? '').'">'.$cont.'</div>').'</li>';
    }
    return $h.'</ul>';
}

/** Minicalendario de asistencia de un mes (celdas de color con tooltip). */
function minicalendario(array $c, string $ym): string {
    $ini = "$ym-01"; $fin = fin_mes($ym); $off = (dia_sem($ini) + 6) % 7;
    $h = '<div class="mcal" role="img" aria-label="Asistencia de '.e(mes_nombre((int)substr($ym,5,2), false)).'">';
    foreach (['L','M','M','J','V','S','D'] as $d) $h .= '<span class="mh">'.$d.'</span>';
    $h .= str_repeat('<span></span>', $off);
    for ($x = $ini; $x <= $fin; $x = add_dias($x, 1)) {
        $k = codigo_dia($c, $x); $m = marca($c['id'], $x);
        $fer = feriado($x);
        $lbl = ($k === 'N' && $fer) ? 'Feriado: '.$fer['nombre'] : (COD[$k] ?? '');
        $tip = f($x).' · '.$lbl.($m ? ' · '.$m['entrada'].($m['salida'] ? ' a '.$m['salida'] : '') : '');
        $h .= '<span class="md c-'.($k === '' || $k === 'S' || $k === 'X' ? 'X' : (in_array($k,['D','L']) ? 'V' : $k)).($x === HOY ? ' hoy' : '').'" data-tip="'.e($tip).'">'.(int)substr($x,8).'</span>';
    }
    return $h.'</div>';
}

/** Medidor simple (porcentaje con pista del mismo tono). */
function medidor(float $p, string $tipo = 'g1'): string { $p = max(0, min(100, $p)); return '<span class="medidor"><i class="'.$tipo.'" style="width:'.round($p,1).'%"></i></span>'; }
