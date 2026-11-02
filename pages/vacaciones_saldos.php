<?php
/**
 * vacaciones_saldos.php — Saldos de vacaciones por persona, con su posición frente al promedio
 * de su área (la marca negra) y el periodo pendiente más antiguo con su vencimiento.
 */
$areaSel = (int)($_GET['area'] ?? 0);
$act = activos(); $s = []; foreach ($act as $c) $s[$c['id']] = saldo_vac($c);
$promArea = []; foreach ($act as $c) { $promArea[$c['areaId']][] = $s[$c['id']]['saldo']; }
$promArea = array_map(fn($l) => array_sum($l) / count($l), $promArea);
$lista = array_values(array_filter($act, fn($c) => !$areaSel || $c['areaId'] === $areaSel));
usort($lista, fn($a, $b) => $s[$b['id']]['saldo'] - $s[$a['id']]['saldo'] ?: strcmp(nom($a), nom($b)));
$max = max(array_merge([30], array_map(fn($x) => $x['saldo'], $s)));
$acum = count(array_filter($lista, fn($c) => $s[$c['id']]['saldo'] >= 60));
$vence = count(array_filter($lista, fn($c) => $s[$c['id']]['viejo'] && $s[$c['id']]['viejo']['vence'] < add_dias(HOY, 90)));
$areas = array_filter(tabla('areas'), fn($a) => $a['activo'] && isset($promArea[$a['id']]));
$barras = array_map(fn($a) => ['et' => $a['nombre'], 'v' => (int)round($promArea[$a['id']]), 'url' => url('vacaciones_saldos', ['area' => $a['id']]), 'tip' => $a['nombre'].': '.num($promArea[$a['id']], 1).' días disponibles por persona'], $areas);
usort($barras, fn($a, $b) => $b['v'] - $a['v']);

$pagina['titulo'] = 'Vacaciones y ausencias';
$pagina['sub'] = 'Saldos al '.f(HOY).' · '.param('diasVacAnio').' días por año de servicio (D. L. 713, pendiente de validación legal)';
$pagina['pestanas'] = pestanas([[url('vacaciones'), 'Solicitudes', false], [url('vacaciones_calendario'), 'Calendario', false], [url('vacaciones_saldos'), 'Saldos', true]]);
?>
<div class="row g-4">
  <div class="col-xl-5">
    <section class="card mb-0 h-100"><div class="cifras cifras-sin-borde py-2">
      <div><div class="l">Días por gozar</div><div class="v"><?= num(array_sum(array_map(fn($c) => max(0, $s[$c['id']]['saldo']), $lista))) ?></div><div class="ctx"><?= $areaSel ? e(por_id('areas', $areaSel)['nombre']) : 'Toda la plantilla activa' ?></div></div>
      <div><div class="l">Con 60 días o más</div><div class="v <?= $acum ? 'text-warning-emphasis' : '' ?>"><?= $acum ?></div><div class="ctx">Acumulan dos periodos o más</div></div>
      <div><div class="l">Periodos por vencer</div><div class="v <?= $vence ? 'text-danger' : '' ?>"><?= $vence ?></div><div class="ctx">Vencen en menos de 90 días</div></div>
      <div><div class="l">Promedio por persona</div><div class="v"><?= $lista ? num(array_sum(array_map(fn($c) => $s[$c['id']]['saldo'], $lista)) / count($lista), 1) : 0 ?></div><div class="ctx">Días disponibles</div></div>
    </div></section>
  </div>
  <div class="col-xl-7">
    <section class="card mb-0 h-100"><div class="card-header"><h2>Saldo promedio por área</h2><span class="ctx">Días disponibles por persona · clic para filtrar</span></div>
      <div class="card-body"><?= barras_h($barras) ?></div></section>
  </div>
</div>

<section class="card mt-4">
  <div class="card-header"><h2>Saldos por persona</h2>
    <?php if ($areaSel): ?><span class="chip"><?= e(por_id('areas', $areaSel)['nombre']) ?><a href="<?= url('vacaciones_saldos') ?>" aria-label="Quitar filtro"><?= ic('x') ?></a></span><?php endif; ?>
    <div class="leyenda ms-auto"><span><i style="background:var(--g1)"></i>Saldo de la persona</span><span><i style="background:var(--tx);width:2px"></i>Promedio de su área</span></div></div>
  <?php if (!$lista): ?>
    <?= estado_vacio('Aún no hay colaboradores activos', 'Registre colaboradores para calcular sus saldos de vacaciones.') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Colaborador</th><th class="num">Años</th><th class="num">Ganados</th><th class="num">Gozados</th><th class="num">Programados</th><th>Disponibles</th><th>Periodo más antiguo</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $c): $x = $s[$c['id']]; $pa = $promArea[$c['areaId']]; $dif = $x['saldo'] - $pa; ?>
      <tr data-href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'vacaciones']) ?>">
        <td><?= celda_nombre($c, e(area($c)), true, 'vacaciones') ?></td>
        <td class="num"><?= $x['anios'] ?></td><td class="num"><?= $x['ganados'] ?></td><td class="num"><?= $x['gozados'] ?></td><td class="num"><?= $x['prog'] ?: vacio_d() ?></td>
        <td><div class="comp-area" data-tip="<?= e($x['saldo'].' días · promedio del área '.num($pa, 1).' · '.(abs($dif) < .5 ? 'en el promedio' : num(abs($dif), 0).' '.($dif > 0 ? 'más' : 'menos'))) ?>">
          <span class="pista"><i style="width:<?= round(max(0, $x['saldo']) / $max * 100, 1) ?>%"></i><s style="left:<?= round($pa / $max * 100, 1) ?>%"></s></span><b class="num <?= $x['saldo'] >= 60 ? 'text-warning-emphasis' : '' ?>"><?= $x['saldo'] ?></b></div></td>
        <td><?php if ($x['viejo']): $v = $x['viejo']['vence']; ?><?= substr($x['viejo']['ini'], 0, 4) ?>–<?= substr($x['viejo']['fin'], 0, 4) ?><span class="sub2 <?= $v < HOY ? 'text-danger fw-bold' : ($v < add_dias(HOY, 90) ? 'text-warning-emphasis fw-bold' : '') ?>"><?= $v < HOY ? 'Venció '.e(hace($v)) : 'Vence '.e(hace($v)) ?></span><?php else: ?><span class="sub2">Al día</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
