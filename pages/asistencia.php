<?php
/**
 * asistencia.php — Asistencia del día: tarjetas por sede (con foto), tardanzas de los últimos 30 días,
 * áreas con más tardanzas y la lista del día con las incidencias primero y los puntuales plegados.
 * La barra «jornada» compara el horario con lo marcado (pase el mouse para ver el detalle).
 */
$fe = fecha_iso($_GET['fecha'] ?? '') ?? HOY; if ($fe > HOY) $fe = HOY;
$sedeSel = (int)($_GET['sede'] ?? 0); $editar = puede('asistencia.editar');
$act = array_values(array_filter(colabs_visibles(), fn($c) => laborable($c, $fe) || ausencia($c, $fe)));
$sedes = array_filter(tabla('sedes'), fn($s) => $s['activo'] && (!empresa_activa() || $s['empresaId'] === empresa_activa()));
$cod = []; foreach ($act as $c) $cod[$c['id']] = codigo_dia($c, $fe);
$lista = array_values(array_filter($act, fn($c) => !$sedeSel || $c['sedeId'] === $sedeSel));
$es = fn($c, array $k) => in_array($cod[$c['id']], $k, true);

/* Tardanzas de los últimos 30 días hábiles */
$dias = []; for ($x = $fe; count($dias) < 30 && $x > add_dias($fe, -60); $x = add_dias($x, -1)) if (dia_sem($x) !== 0) array_unshift($dias, $x);
$serie = []; $suma = 0; $porArea = [];
$todos = activos();
foreach ($dias as $x) { $t = 0;
    foreach ($todos as $c) { if (!laborable($c, $x) || ($sedeSel && $c['sedeId'] !== $sedeSel)) continue; if (codigo_dia($c, $x) === 'T') { $t++; $porArea[area($c)] = ($porArea[area($c)] ?? 0) + 1; } }
    $suma += $t; $serie[] = ['et' => (int)substr($x, 8).'/'.(int)substr($x, 5, 2), 'v' => $t, 'tip' => mb_convert_case(DIAS[dia_sem($x)], MB_CASE_TITLE).' '.f($x).': '.pl($t, 'tardanza', 'tardanzas')]; }
arsort($porArea); $prom = $dias ? $suma / count($dias) : 0;

$pagina['titulo'] = 'Asistencia';
$pagina['sub'] = e(ucfirst(fecha_larga($fe))).($fe === HOY ? ' · hoy' : ' · '.e(hace($fe)));
$pagina['pestanas'] = pestanas([[url('asistencia'), 'Del día', true], [url('asistencia_mensual'), 'Tareo mensual', false], [url('horarios'), 'Horarios', false], ...(puede('asistencia.editar') ? [[url('importar_marcaciones'), 'Importar marcaciones', false]] : [])]);
$navF = fn($x) => url('asistencia', array_filter(['fecha' => $x === HOY ? '' : f($x), 'sede' => $sedeSel ?: '']));
$ant = add_dias($fe, -1); if (dia_sem($ant) === 0) $ant = add_dias($ant, -1);
$sig = add_dias($fe, 1); if (dia_sem($sig) === 0) $sig = add_dias($sig, 1);
$pagina['acciones'] = '<div class="mes-nav"><a class="btn btn-sec btn-sm btn-ico" href="'.$navF($ant).'" aria-label="Día anterior">'.ic('caret-left').'</a>'
    .'<form action="index.php" class="d-inline"><input type="hidden" name="p" value="asistencia"><input class="form-control form-control-sm" name="fecha" value="'.f($fe).'" data-fecha aria-label="Fecha" style="width:110px;text-align:center" onchange="this.form.submit()"></form>'
    .($fe < HOY ? '<a class="btn btn-sec btn-sm btn-ico" href="'.$navF(min($sig, HOY)).'" aria-label="Día siguiente">'.ic('caret-right').'</a><a class="btn btn-ter btn-sm" href="'.url('asistencia').'">Hoy</a>' : '').'</div>'
    .'<a class="btn btn-sec" href="index.php?exportar=asistencia&fecha='.f($fe).'">'.ic('download-simple').' Exportar</a>';

/* Jornada: horario frente a marcación en una escala de 6:00 a 21:00 */
$jornada = function (array $c, ?array $m) use ($fe) {
    $h = horario($c); $x0 = 360; $span = 900; $pos = fn($min) => round(max(0, min(100, ($min - $x0) / $span * 100)), 2);
    $he = min_a_hora($h['entrada']); $hs = min_a_hora($h['salida']);
    $tip = 'Horario '.$h['entrada'].'–'.$h['salida'];
    $s = '<div class="jor"><span class="p" style="left:'.$pos($he).'%;width:'.($pos($hs) - $pos($he)).'%"></span>';
    if ($m) { $me = min_a_hora($m['entrada']); $ms = $m['salida'] ? min_a_hora($m['salida']) : ($fe === HOY ? min((int)date('G') * 60 + (int)date('i'), 1260) : $me + 30);
        $s .= '<span class="w" style="left:'.$pos($me).'%;width:'.max(.6, $pos($ms) - $pos($me)).'%"></span>';
        $tard = estado_marca($m)['tard'];
        if ($tard) $s .= '<span class="t" style="left:'.$pos($he).'%;width:'.max(.6, $pos($me) - $pos($he)).'%"></span>';
        $tip .= ' · marcó '.$m['entrada'].($m['salida'] ? ' a '.$m['salida'] : ($fe === HOY ? ', aún en labores' : ', sin salida')).($tard ? ' · '.$tard.' min tarde' : '').(($e = estado_marca($m)['min']) ? ' · '.duracion($e) : ''); }
    else $tip .= ' · sin marcación';
    return '<div data-tip="'.e($tip).'">'.$s.'</div></div>';
};
$grupos = [
    ['Sin marcar entrada', fn($c) => $es($c, ['S']), 'er'],
    ['Faltas', fn($c) => $es($c, ['F']), 'er'],
    ['Tardanzas', fn($c) => $es($c, ['T']), 'wa'],
    ['Salida sin marcar', fn($c) => $es($c, ['A']) && $fe < HOY && !(marca($c['id'], $fe)['salida'] ?? ''), 'wa'],
    ['Ausencias justificadas', fn($c) => $es($c, ['V', 'D', 'L']), 'in'],
    ['Puntuales', fn($c) => $es($c, ['A']) && !($fe < HOY && !(marca($c['id'], $fe)['salida'] ?? '')), 'ok'],
];
?>
<div class="sedes-tarjetas">
  <?php foreach ($sedes as $s):
    $ps = array_filter($act, fn($c) => $c['sedeId'] === $s['id'] && !$es($c, ['V', 'D', 'L'])); if (!$ps) continue;
    $pr = count(array_filter($ps, fn($c) => $es($c, ['A', 'T']))); $ta = count(array_filter($ps, fn($c) => $es($c, ['T']))); $p100 = $pr / count($ps) * 100; ?>
  <a class="sede-t <?= $sedeSel === $s['id'] ? 'on' : '' ?>" href="<?= url('asistencia', array_filter(['fecha' => $fe === HOY ? '' : f($fe), 'sede' => $sedeSel === $s['id'] ? '' : $s['id']])) ?>" aria-pressed="<?= $sedeSel === $s['id'] ? 'true' : 'false' ?>">
    <?= foto_sede($s) ?>
    <span class="cuerpo"><b><?= e($s['nombre']) ?></b>
      <span class="fila"><span>Presentes</span><b><?= $pr ?> de <?= count($ps) ?></b></span>
      <?= medidor($p100, $p100 < 90 ? 'wa' : 'g1') ?>
      <span class="fila"><span>Tardanzas</span><b class="<?= $ta ? 'text-warning-emphasis' : '' ?>"><?= $ta ?></b></span></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-xl-8">
    <section class="card mb-0 h-100"><div class="card-header"><h2>Tardanzas por día</h2><span class="ctx">Últimos 30 días hábiles<?= $sedeSel ? ' · '.e(por_id('sedes', $sedeSel)['nombre']) : '' ?></span></div>
      <div class="card-body"><?= columnas($serie, ['alto' => 180, 'ref' => $prom, 'ref_txt' => 'Promedio '.num($prom, 1), 'cada' => 3, 'titulo' => 'Tardanzas por día en los últimos 30 días hábiles']) ?></div></section>
  </div>
  <div class="col-xl-4">
    <section class="card mb-0 h-100"><div class="card-header"><h2>Tardanzas por área</h2><span class="ctx">Mismo periodo</span></div>
      <div class="card-body"><?= $porArea ? barras_h(array_map(fn($k, $v) => ['et' => $k, 'v' => $v, 'tip' => $k.': '.pl($v, 'tardanza', 'tardanzas').' en 30 días'], array_keys(array_slice($porArea, 0, 7)), array_slice($porArea, 0, 7))) : '<p class="sub2 mb-0">Sin tardanzas en el periodo.</p>' ?></div></section>
  </div>
</div>

<section class="card mt-4">
  <div class="card-header"><h2>Detalle del día</h2>
    <span class="ctx"><?= $sedeSel ? e(por_id('sedes', $sedeSel)['nombre']).' · <a href="'.url('asistencia', array_filter(['fecha' => $fe === HOY ? '' : f($fe)])).'">ver todas las sedes</a>' : 'Todas las sedes' ?> · <?= pl(count($lista), 'persona programada', 'personas programadas') ?></span></div>
  <?php if (!$lista): ?><?= estado_vacio('Nadie tiene jornada programada este día', dia_sem($fe) === 0 ? 'Es domingo.' : '') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Colaborador</th><th style="min-width:240px">Jornada <span class="jorh"><span style="left:0">6:00</span><span style="left:40%">12:00</span><span style="left:80%">18:00</span></span></th><th>Entrada</th><th>Salida</th><th>Estado</th><?= $editar ? '<th></th>' : '' ?></tr></thead>
    <?php foreach ($grupos as $gi => [$tit, $fn, $tono]): $g = array_values(array_filter($lista, $fn)); if (!$g) continue;
      usort($g, fn($a, $b) => strcmp(nom($a), nom($b))); $plegado = $tit === 'Puntuales' && count($lista) > 12; ?>
    <tbody>
      <tr class="fila-grupo"><td colspan="<?= $editar ? 6 : 5 ?>"><span class="st <?= $tono ?>"><?= e($tit) ?> · <?= count($g) ?></span>
        <?php if ($plegado): ?><button type="button" class="btn btn-ter btn-sm ms-2" data-plegar="#g-<?= $gi ?>" aria-expanded="false" aria-controls="g-<?= $gi ?>">Mostrar <?= ic('caret-down') ?></button><?php endif; ?></td></tr>
    </tbody>
    <tbody id="g-<?= $gi ?>" <?= $plegado ? 'hidden' : '' ?>>
      <?php foreach ($g as $c): $m = marca($c['id'], $fe); $k = $cod[$c['id']]; $a = in_array($k, ['V', 'D', 'L'], true) ? ausencia($c, $fe) : null; $em = $m ? estado_marca($m) : null; ?>
      <tr data-href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'asistencia']) ?>">
        <td><?= celda_nombre($c, e(area($c)).' · '.e(sede($c)), true, 'asistencia') ?></td>
        <td><?= $a ? '<span class="sub2">'.e($a['tipo']).' del '.f($a['desde']).' al '.f($a['hasta']).'</span>' : $jornada($c, $m) ?></td>
        <td><?= $m ? '<b class="'.($em['tard'] ? 'text-warning-emphasis' : '').'">'.e($m['entrada']).'</b>'.($em['tard'] ? '<span class="sub2">'.$em['tard'].' min tarde</span>' : '') : vacio_d() ?></td>
        <td><?= $m && $m['salida'] ? e($m['salida']) : ($m && $fe < HOY ? '<span class="text-warning-emphasis">Sin salida</span>' : vacio_d()) ?></td>
        <td><?= ['A' => estado('Activo', 'A tiempo'), 'T' => '<span class="st wa">Tardanza</span>', 'F' => '<span class="st er">Falta</span>', 'S' => '<span class="st er">Sin marcar</span>', 'V' => '<span class="st in">Vacaciones</span>', 'D' => '<span class="st in">Descanso médico</span>', 'L' => '<span class="st in">Permiso</span>'][$k] ?? '' ?>
          <?= $m && $m['origen'] === 'Web' ? '<span class="sub2" data-tip="'.e($m['obs']).'">Corregida</span>' : '' ?></td>
        <?php if ($editar): ?><td class="text-end"><?= !$a ? btn_panel('marcar', ['id' => $c['id'], 'fecha' => f($fe)], $m ? 'Corregir' : 'Registrar', 'btn btn-ter btn-sm') : '' ?></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
