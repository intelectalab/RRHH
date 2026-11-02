<?php
/**
 * asistencia_mensual.php — Tareo del mes: una fila por persona y una columna por día con el código
 * del día (A, T, F, V…), totales por persona a la derecha y presentes por día al pie.
 */
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : substr(HOY, 0, 7); if ($ym > substr(HOY, 0, 7)) $ym = substr(HOY, 0, 7);
$areaSel = (int)($_GET['area'] ?? 0);
$ini = "$ym-01"; $fin = fin_mes($ym);
$gente = array_values(array_filter(colabs_visibles(), fn($c) => $c['fecIngreso'] <= $fin && !($c['fecCese'] && $c['fecCese'] < $ini) && (!$areaSel || $c['areaId'] === $areaSel)));
usort($gente, fn($a, $b) => strcmp(area($a).nom($a), area($b).nom($b)));
$diasMes = []; for ($x = $ini; $x <= $fin; $x = add_dias($x, 1)) $diasMes[] = $x;
$ant = substr(add_meses("$ym-15", -1), 0, 7); $sig = substr(add_meses("$ym-15", 1), 0, 7);

$pagina['titulo'] = 'Asistencia';
$pagina['sub'] = 'Tareo de '.mes_nombre((int)substr($ym, 5), false).' de '.substr($ym, 0, 4).' · '.pl(count($gente), 'persona', 'personas');
$pagina['pestanas'] = pestanas([[url('asistencia'), 'Del día', false], [url('asistencia_mensual'), 'Tareo mensual', true], [url('horarios'), 'Horarios', false], ...(puede('asistencia.editar') ? [[url('importar_marcaciones'), 'Importar marcaciones', false]] : [])]);
$pagina['acciones'] = '<div class="mes-nav"><a class="btn btn-sec btn-sm btn-ico" href="'.url('asistencia_mensual', ['mes' => $ant, 'area' => $areaSel ?: '']).'" aria-label="Mes anterior">'.ic('caret-left').'</a><b>'.mes_nombre((int)substr($ym, 5)).' '.substr($ym, 0, 4).'</b>'
    .($ym < substr(HOY, 0, 7) ? '<a class="btn btn-sec btn-sm btn-ico" href="'.url('asistencia_mensual', ['mes' => $sig, 'area' => $areaSel ?: '']).'" aria-label="Mes siguiente">'.ic('caret-right').'</a>' : '<span class="btn btn-sec btn-sm btn-ico disabled" aria-hidden="true">'.ic('caret-right').'</span>').'</div>'
    .'<button type="button" class="btn btn-sec" data-imprimir>'.ic('printer').' Imprimir</button>';
$presentes = array_fill_keys($diasMes, 0); $programados = array_fill_keys($diasMes, 0);
?>
<section class="card">
  <div class="card-header">
    <form class="d-flex gap-2 align-items-center" action="index.php"><input type="hidden" name="p" value="asistencia_mensual"><input type="hidden" name="mes" value="<?= e($ym) ?>">
      <select class="form-select form-select-sm" name="area" aria-label="Área" onchange="this.form.submit()" style="width:auto"><option value="">Todas las áreas</option><?= opciones(array_filter(tabla('areas'), fn($a) => $a['activo']), $areaSel, fn($a) => $a['nombre']) ?></select>
    </form>
    <div class="leyenda"><?php foreach (['A' => 'Asistió', 'T' => 'Tardanza', 'F' => 'Falta', 'V' => 'Vacaciones', 'D' => 'Descanso médico', 'L' => 'Permiso'] as $k => $t): ?><span><b class="cod-l c-<?= in_array($k, ['D', 'L']) ? 'V' : $k ?>"><?= $k ?></b><?= $t ?></span><?php endforeach; ?></div>
  </div>
  <?php if (!$gente): ?><?= estado_vacio('No hay personas en este mes') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table matriz mb-0">
    <thead><tr><th class="nom">Colaborador</th>
      <?php foreach ($diasMes as $x): ?><th class="<?= dia_sem($x) === 0 ? 'fs' : '' ?> <?= $x === HOY ? 'hoy-col' : '' ?>"><?= DIAS1[dia_sem($x)] ?><br><?= (int)substr($x, 8) ?></th><?php endforeach; ?>
      <th class="tot">A</th><th class="tot">T</th><th class="tot">F</th><th class="tot">Aus.</th></tr></thead>
    <tbody>
    <?php $areaAnt = null; foreach ($gente as $c): if (area($c) !== $areaAnt): $areaAnt = area($c); ?>
      <tr class="fila-grupo"><td colspan="<?= count($diasMes) + 5 ?>"><?= e($areaAnt) ?></td></tr>
    <?php endif; $t = ['A' => 0, 'T' => 0, 'F' => 0, 'J' => 0]; ?>
      <tr><td class="nom"><div class="who"><?= avatar($c, 's') ?><a class="nm text-truncate" href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'asistencia', 'mes' => $ym]) ?>"><?= e(nom($c)) ?></a></div></td>
      <?php foreach ($diasMes as $x): $k = codigo_dia($c, $x); $m = in_array($k, ['A', 'T'], true) ? marca($c['id'], $x) : null;
        if (in_array($k, ['A', 'T', 'F'], true)) $programados[$x]++; if (in_array($k, ['A', 'T'], true)) $presentes[$x]++;
        if (isset($t[$k])) $t[$k]++; if (in_array($k, ['V', 'D', 'L'], true)) $t['J']++;
        $fer = feriado($x);
        $lblCod = ($k === 'N' && $fer) ? 'Feriado: '.$fer['nombre'] : (COD[$k] ?? '');
        $vis = in_array($k, ['N', 'X', '', 'S'], true) ? ($k === 'S' ? '·' : '') : $k; ?>
        <td class="cod <?= $vis !== '' && $vis !== '·' ? 'c-'.(in_array($k, ['D', 'L']) ? 'V' : $k) : '' ?> <?= dia_sem($x) === 0 ? 'fs' : '' ?>" data-tip="<?= e(nomc($c).' · '.f($x).' · '.$lblCod.($m ? ' · '.$m['entrada'].($m['salida'] ? '–'.$m['salida'] : '') : '')) ?>"><?= $vis ?></td>
      <?php endforeach; ?>
        <td class="tot"><?= $t['A'] ?></td><td class="tot <?= $t['T'] ? 'text-warning-emphasis' : '' ?>"><?= $t['T'] ?></td><td class="tot <?= $t['F'] ? 'text-danger' : '' ?>"><?= $t['F'] ?></td><td class="tot"><?= $t['J'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td class="nom">Presentes / programados</td>
      <?php foreach ($diasMes as $x): ?><td class="<?= dia_sem($x) === 0 ? 'fs' : '' ?>" <?= $programados[$x] ? 'data-tip="'.e(f($x).': '.$presentes[$x].' de '.$programados[$x].' presentes ('.pct($presentes[$x], $programados[$x]).')').'"' : '' ?>><?= $programados[$x] ? $presentes[$x] : '' ?></td><?php endforeach; ?>
      <td colspan="4"></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</section>
