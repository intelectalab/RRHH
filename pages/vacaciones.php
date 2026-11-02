<?php
/**
 * vacaciones.php — Solicitudes de vacaciones, permisos y descansos médicos: cifras del periodo,
 * días programados por mes y la lista filtrable por estado y tipo con acceso a «Revisar».
 */
$ids = array_column(colabs_visibles(), 'id');
$todas = array_values(array_filter(tabla('vacaciones'), fn($v) => in_array($v['colabId'], $ids, true)));
$est = $_GET['estado'] ?? 'Pendiente'; $tipo = $_GET['tipo'] ?? '';
$estados = ['Pendiente' => 'Por aprobar', 'Aprobada' => 'Aprobadas', 'Rechazada' => 'Rechazadas', 'todas' => 'Todas'];
if (!isset($estados[$est])) $est = 'Pendiente';
$lista = array_values(array_filter($todas, fn($v) => ($est === 'todas' || $v['estado'] === $est) && (!$tipo || $v['tipo'] === $tipo) && ($est !== 'todas' || $v['hasta'] >= add_meses(HOY, -6))));
usort($lista, fn($a, $b) => $est === 'Pendiente' ? strcmp($a['fecSol'], $b['fecSol']) : strcmp($b['desde'], $a['desde']));
$aprobar = puede('vacaciones.aprobar');

$aus = count(array_filter(activos(), fn($c) => ausencia($c, HOY)));
$prox30 = array_filter($todas, fn($v) => $v['estado'] === 'Aprobada' && $v['desde'] > HOY && $v['desde'] <= add_dias(HOY, 30));
$pend = array_filter($todas, fn($v) => $v['estado'] === 'Pendiente');
$saldo = array_sum(array_map(fn($c) => max(0, saldo_vac($c)['saldo']), activos()));
$meses = []; for ($i = -2; $i < 6; $i++) { $ym = substr(add_meses(substr(HOY, 0, 7).'-15', $i), 0, 7); $d = 0;
    foreach ($todas as $v) if ($v['estado'] === 'Aprobada' && $v['tipo'] === 'Vacaciones') for ($x = max($v['desde'], "$ym-01"); $x <= min($v['hasta'], fin_mes($ym)); $x = add_dias($x, 1)) $d++;
    $meses[] = ['et' => MES3[(int)substr($ym, 5) - 1], 'v' => $d, 'tip' => mes_nombre((int)substr($ym, 5)).' '.substr($ym, 0, 4).': '.pl($d, 'día de vacaciones', 'días de vacaciones'), 'clase' => $ym < substr(HOY, 0, 7) ? 'pasado' : '']; }

$pagina['titulo'] = 'Vacaciones y ausencias';
$pagina['sub'] = pl(count($pend), 'solicitud por aprobar', 'solicitudes por aprobar').' · '.pl($aus, 'persona ausente hoy', 'personas ausentes hoy');
$pagina['pestanas'] = pestanas([[url('vacaciones'), 'Solicitudes', true, count($pend) ?: null], [url('vacaciones_calendario'), 'Calendario', false], [url('vacaciones_saldos'), 'Saldos', false]]);
$pagina['acciones'] = puede('personal.editar') ? btn_panel('solicitud', [], ic('plus').' Registrar ausencia', 'btn btn-primary') : '';
?>
<div class="row g-4">
  <div class="col-xl-5">
    <section class="card mb-0 h-100" aria-label="Cifras"><div class="cifras cifras-sin-borde py-2">
      <div><div class="l">Por aprobar</div><div class="v"><?= count($pend) ?></div><div class="ctx"><?= $pend ? 'La más antigua espera '.e(hace(min(array_column($pend, 'fecSol')))) : 'Nada pendiente' ?></div></div>
      <div><div class="l">Ausentes hoy</div><div class="v"><?= $aus ?></div><div class="ctx">Vacaciones, descansos y permisos</div></div>
      <div><div class="l">Salen en 30 días</div><div class="v"><?= count($prox30) ?></div><div class="ctx"><?= $prox30 ? grupo_fotos(array_map(fn($v) => por_id('colaboradores', $v['colabId']), array_values($prox30)), 5) : 'Nadie programado' ?></div></div>
      <div><div class="l">Días por gozar</div><div class="v"><?= num($saldo) ?></div><div class="ctx"><?= activos() ? num($saldo / count(activos()), 1).' por persona en promedio' : '' ?></div></div>
    </div></section>
  </div>
  <div class="col-xl-7">
    <section class="card mb-0 h-100"><div class="card-header"><h2>Días de vacaciones por mes</h2><span class="ctx">Aprobadas · dos meses atrás y seis adelante</span></div>
      <div class="card-body"><?= columnas($meses, ['alto' => 150, 'titulo' => 'Días de vacaciones aprobados por mes']) ?></div></section>
  </div>
</div>

<section class="card mt-4">
  <div class="card-header">
    <nav class="seg" aria-label="Estado"><?php foreach ($estados as $k => $t): $n = $k === 'todas' ? null : count(array_filter($todas, fn($v) => $v['estado'] === $k)); ?><a href="<?= url('vacaciones', array_filter(['estado' => $k === 'Pendiente' ? '' : $k, 'tipo' => $tipo])) ?>" class="<?= $est === $k ? 'on' : '' ?>"><?= e($t) ?><?= $n !== null ? '<span class="n">'.$n.'</span>' : '' ?></a><?php endforeach; ?></nav>
    <nav class="seg" aria-label="Tipo"><?php foreach (['' => 'Todos los tipos', 'Vacaciones' => 'Vacaciones', 'Permiso' => 'Permisos', 'Descanso médico' => 'Descansos médicos'] as $k => $t): ?><a href="<?= url('vacaciones', array_filter(['estado' => $est === 'Pendiente' ? '' : $est, 'tipo' => $k])) ?>" class="<?= $tipo === $k ? 'on' : '' ?>"><?= e($t) ?></a><?php endforeach; ?></nav>
  </div>
  <?php if (!$lista): ?>
    <?= $est === 'Pendiente' ? estado_vacio('No hay solicitudes por aprobar', 'Cuando alguien pida vacaciones o un permiso, aparecerá aquí.') : estado_vacio('No hay solicitudes con este filtro') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Colaborador</th><th>Tipo</th><th>Fechas</th><th class="num">Días</th><th>Saldo</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $v): $c = por_id('colaboradores', $v['colabId']); $s = saldo_vac($c); $cruces = $v['estado'] === 'Pendiente' ? ausentes_area($v) : []; ?>
      <tr>
        <td><?= celda_nombre($c, e(puesto($c)).' · '.e(area($c)), true, 'vacaciones') ?></td>
        <td><?= e($v['tipo']) ?><span class="sub2">Pedida <?= e(hace($v['fecSol'])) ?></span></td>
        <td><?= f($v['desde']) ?> al <?= f($v['hasta']) ?><span class="sub2"><?= $v['hasta'] < HOY ? 'Terminó '.e(hace($v['hasta'])) : ($v['desde'] <= HOY ? 'En curso' : 'Empieza '.e(hace($v['desde']))) ?><?= $cruces ? ' · <span class="text-warning-emphasis fw-bold" data-tip="'.e('También ausentes: '.implode(', ', array_map('nomc', $cruces))).'">'.pl(count($cruces), 'cruce', 'cruces').' en el área</span>' : '' ?></span></td>
        <td class="num"><?= $v['dias'] ?></td>
        <td><?= $v['tipo'] === 'Vacaciones' ? '<span class="'.($v['estado'] === 'Pendiente' && $s['saldo'] < $v['dias'] ? 'text-danger fw-bold' : '').'">'.$s['saldo'].' días</span>' : vacio_d() ?></td>
        <td><?= estado($v['estado']) ?><span class="sub2"><?= $v['resuelto'] ? e($v['resuelto']) : '' ?></span></td>
        <td class="text-end"><?= $v['estado'] === 'Pendiente' && $aprobar ? btn_panel('revisar', ['id' => $v['id']], 'Revisar', 'btn btn-sec btn-sm') : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
