<?php
/**
 * vacaciones_calendario.php — Calendario (Gantt) de seis semanas con fotos: quién sale y cuándo,
 * agrupado por área. En la cabecera, cuántas personas faltan cada día; en ámbar si coinciden dos o más del área.
 */
$ref = fecha_iso($_GET['desde'] ?? '') ?? HOY;
$ini = add_dias($ref, -((dia_sem($ref) + 6) % 7)); $fin = add_dias($ini, 41);
$areaSel = (int)($_GET['area'] ?? 0); $todos = !empty($_GET['todos']);
$act = array_values(array_filter(activos(), fn($c) => !$areaSel || $c['areaId'] === $areaSel));
$aus = []; foreach (tabla('vacaciones') as $v) if (in_array($v['estado'], ['Aprobada', 'Pendiente'], true) && $v['desde'] <= $fin && $v['hasta'] >= $ini) $aus[$v['colabId']][] = $v;
$filas = array_values(array_filter($act, fn($c) => $todos || isset($aus[$c['id']])));
usort($filas, fn($a, $b) => strcmp(area($a).nom($a), area($b).nom($b)));
$dias = []; for ($x = $ini; $x <= $fin; $x = add_dias($x, 1)) $dias[] = $x;
/* Ausentes por día (aprobadas) y por área para detectar cruces */
$porDia = []; $porDiaArea = [];
foreach ($act as $c) foreach ($aus[$c['id']] ?? [] as $v) if ($v['estado'] === 'Aprobada') foreach ($dias as $x) if ($x >= $v['desde'] && $x <= $v['hasta']) { $porDia[$x][] = nomc($c); $porDiaArea[$x][$c['areaId']] = ($porDiaArea[$x][$c['areaId']] ?? 0) + 1; }

$pagina['titulo'] = 'Vacaciones y ausencias';
$pagina['sub'] = 'Del '.f($ini).' al '.f($fin);
$pagina['pestanas'] = pestanas([[url('vacaciones'), 'Solicitudes', false], [url('vacaciones_calendario'), 'Calendario', true], [url('vacaciones_saldos'), 'Saldos', false]]);
$nav = fn($x) => url('vacaciones_calendario', array_filter(['desde' => f($x), 'area' => $areaSel ?: '', 'todos' => $todos ? 1 : '']));
$pagina['acciones'] = '<div class="mes-nav"><a class="btn btn-sec btn-sm btn-ico" href="'.$nav(add_dias($ini, -28)).'" aria-label="Cuatro semanas antes">'.ic('caret-left').'</a><a class="btn btn-ter btn-sm" href="'.$nav(HOY).'">Hoy</a><a class="btn btn-sec btn-sm btn-ico" href="'.$nav(add_dias($ini, 28)).'" aria-label="Cuatro semanas después">'.ic('caret-right').'</a></div>';
$clase = ['Vacaciones' => '', 'Descanso médico' => 'dm', 'Permiso' => 'pm'];
?>
<section class="card">
  <div class="card-header">
    <form class="d-flex gap-2 align-items-center flex-wrap" action="index.php"><input type="hidden" name="p" value="vacaciones_calendario"><input type="hidden" name="desde" value="<?= f($ref) ?>">
      <select class="form-select form-select-sm" name="area" aria-label="Área" onchange="this.form.submit()" style="width:auto"><option value="">Todas las áreas</option><?= opciones(array_filter(tabla('areas'), fn($a) => $a['activo']), $areaSel, fn($a) => $a['nombre']) ?></select>
      <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="c-todos" name="todos" value="1" <?= $todos ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label small" for="c-todos">Mostrar también a quienes no tienen ausencias</label></div>
    </form>
    <div class="leyenda"><span><i style="background:var(--g1)"></i>Vacaciones</span><span><i style="background:#7A8CA3"></i>Descanso médico</span><span><i style="background:#5E9E6E"></i>Permiso</span><span><i style="background:var(--oc-50);outline:1.5px dashed var(--oc);outline-offset:-1.5px"></i>Por aprobar</span><span><b class="cr d-inline-block">2</b> dos o más del área el mismo día</span></div>
  </div>
  <?php if (!$filas): ?><?= estado_vacio('Nadie tiene ausencias en estas seis semanas', 'Use las flechas para ver otras fechas.') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table gantt mb-0">
    <thead><tr><th class="nom">Colaborador</th>
      <?php foreach ($dias as $x): $n = count($porDia[$x] ?? []); $cruce = max($porDiaArea[$x] ?? [0]) >= 2; ?>
      <th class="<?= in_array(dia_sem($x), [0, 6]) ? 'fs' : '' ?> <?= $x === HOY ? 'hoy' : '' ?>" <?= $n ? 'data-tip="'.e(f($x).': '.implode(', ', $porDia[$x])).'"' : '' ?>>
        <?= $n ? '<span class="cr '.($cruce ? '' : 'suave').'">'.$n.'</span>' : '<span class="cr vacio"></span>' ?><?= DIAS1[dia_sem($x)] ?><br><?= (int)substr($x, 8) ?><?= substr($x, 8) === '01' || $x === $ini ? '<br><small>'.MES3[(int)substr($x, 5, 2) - 1].'</small>' : '' ?></th>
      <?php endforeach; ?></tr></thead>
    <tbody>
    <?php $areaAnt = null; foreach ($filas as $c): if (area($c) !== $areaAnt): $areaAnt = area($c); ?>
      <tr class="fila-grupo"><td colspan="<?= count($dias) + 1 ?>"><?= e($areaAnt) ?></td></tr>
    <?php endif; ?>
      <tr><td class="nom"><div class="who"><?= avatar($c, 's') ?><a class="nm text-truncate" href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'vacaciones']) ?>"><?= e(nom($c)) ?></a></div></td>
      <?php foreach ($dias as $x): $v = null; foreach ($aus[$c['id']] ?? [] as $y) if ($x >= $y['desde'] && $x <= $y['hasta']) { $v = $y; break; } ?>
        <td class="<?= in_array(dia_sem($x), [0, 6]) ? 'fs' : '' ?> <?= $x === HOY ? 'hoy' : '' ?>"><?php if ($v):
          $i = $x === $v['desde'] || $x === $ini; $f = $x === $v['hasta'] || $x === $fin; $r = por_id('colaboradores', (int)($v['reemplazoId'] ?? 0)); ?>
          <div class="b <?= $v['estado'] === 'Pendiente' ? 'p' : $clase[$v['tipo']] ?> <?= $i && $f ? 'if' : ($i ? 'i' : ($f ? 'f' : '')) ?>" data-tip="<?= e(nomc($c).' · '.$v['tipo'].($v['estado'] === 'Pendiente' ? ' (por aprobar)' : '').' del '.f($v['desde']).' al '.f($v['hasta']).' · '.pl($v['dias'], 'día', 'días').($r ? ' · reemplazo: '.nomc($r) : '')) ?>"></div>
        <?php endif; ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
