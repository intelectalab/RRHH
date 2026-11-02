<?php
/**
 * mis_vacaciones.php — Vacaciones de la persona que ingresó: saldo, periodos, próximas salidas,
 * sus solicitudes (con opción de anular las pendientes) y quién del equipo sale en las próximas semanas.
 */
$c = yo();
$pagina['titulo'] = 'Mis vacaciones';
if (!$c) { echo estado_vacio('Su usuario no está vinculado a un colaborador', 'Pida al área de RR. HH. que vincule su usuario con su ficha.'); return; }
$s = saldo_vac($c);
$vacs = array_values(array_filter(tabla('vacaciones'), fn($v) => $v['colabId'] === $c['id'])); usort($vacs, fn($a, $b) => strcmp($b['desde'], $a['desde']));
$equipo = array_values(array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && $x['areaId'] === $c['areaId'] && $x['id'] !== $c['id']));
$salen = []; foreach (tabla('vacaciones') as $v) if ($v['estado'] === 'Aprobada' && $v['hasta'] >= HOY && $v['desde'] <= add_dias(HOY, 45)) foreach ($equipo as $x) if ($x['id'] === $v['colabId']) $salen[] = [$x, $v];
usort($salen, fn($a, $b) => strcmp($a[1]['desde'], $b[1]['desde']));
$pagina['sub'] = e(nomc($c)).' · '.pl($s['saldo'], 'día disponible', 'días disponibles');
$pagina['acciones'] = btn_panel('solicitud', ['id' => $c['id']], ic('plus').' Solicitar vacaciones o permiso', 'btn btn-primary');
?>
<div class="row g-4">
  <div class="col-lg-5">
    <section class="card destacada mb-0"><div class="card-header"><h2>Mi saldo</h2></div>
      <div class="card-body"><?= barra_saldo($s) ?></div>
      <?php if ($s['per']): ?><div class="table-responsive"><table class="table mb-0 tabla-mini"><thead><tr><th>Periodo</th><th class="num">Ganados</th><th class="num">Usados</th><th>Vence</th></tr></thead><tbody>
        <?php foreach (array_reverse($s['per']) as $pe): ?><tr><td><?= f($pe['ini']) ?> – <?= f($pe['fin']) ?></td><td class="num"><?= $pe['dias'] ?></td><td class="num"><?= $pe['usados'] ?></td><td><?= $pe['usados'] >= $pe['dias'] ? '<span class="sub2 d-inline">Gozado</span>' : f($pe['vence']).' <span class="sub2 d-inline">('.e(hace($pe['vence'])).')</span>' ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php else: ?><div class="card-body pt-0 sub2">Su primer periodo se completa el <?= f(add_dias(add_meses($c['fecIngreso'], 12), -1)) ?> (<?= e(hace(add_dias(add_meses($c['fecIngreso'], 12), -1))) ?>).</div><?php endif; ?>
    </section>
    <section class="bloque-plano mt-4"><div class="cab"><h2>Quién sale en su área</h2><span class="sub2">Próximas 6 semanas</span></div>
      <?php if ($salen): ?><ul class="lista tarjeta-lista"><?php foreach ($salen as [$x, $v]): ?><li><?= avatar($x, 'm') ?><div class="min-w-0 flex-grow-1"><b class="d-block"><?= e(nomc($x)) ?></b><span class="sub2"><?= f($v['desde']) ?> al <?= f($v['hasta']) ?> · <?= $v['desde'] <= HOY ? 'en curso' : e(hace($v['desde'])) ?></span></div></li><?php endforeach; ?></ul>
      <?php else: ?><p class="sub2">Nadie de su área tiene salidas programadas.</p><?php endif; ?></section>
  </div>
  <div class="col-lg-7">
    <section class="card mb-0"><div class="card-header"><h2>Mis solicitudes</h2><span class="ctx"><?= pl(count($vacs), 'registro', 'registros') ?></span></div>
      <?php if ($vacs): ?><ul class="tl tl-comp pb-2">
        <?php foreach ($vacs as $v): $r = por_id('colaboradores', (int)($v['reemplazoId'] ?? 0)); $t = ['Pendiente' => ['hourglass', 'oc'], 'Aprobada' => ['check', 'ok'], 'Rechazada' => ['x', 'er'], 'Anulada' => ['minus', '']][$v['estado']] ?? ['circle', '']; ?>
        <li><span class="ic <?= $t[1] ?>"><?= ic($t[0]) ?></span><div class="d-flex gap-2 align-items-start"><div class="flex-grow-1 min-w-0">
          <b><?= e($v['tipo']) ?> · <?= pl($v['dias'], 'día', 'días') ?></b> <?= estado($v['estado']) ?>
          <span class="sub2"><?= f($v['desde']) ?> al <?= f($v['hasta']) ?> · pedida <?= e(hace($v['fecSol'])) ?><?= $v['resuelto'] ? ' · resuelta por '.e($v['resuelto']) : '' ?><?= $r ? ' · reemplazo: '.e(nomc($r)) : '' ?></span>
          <?= $v['estado'] === 'Rechazada' && $v['obs'] ? '<span class="sub2 text-danger">Motivo: '.e($v['obs']).'</span>' : '' ?></div>
          <?= $v['estado'] === 'Pendiente' ? btn_panel('confirmar', ['que' => 'anular', 'id' => $v['id']], 'Anular', 'btn btn-ter btn-sm') : '' ?></div></li>
        <?php endforeach; ?></ul>
      <?php else: ?><?= estado_vacio('Aún no tiene solicitudes', 'Cuando pida vacaciones o un permiso, podrá seguir su estado aquí.', btn_panel('solicitud', ['id' => $c['id']], 'Solicitar vacaciones', 'btn btn-primary btn-sm')) ?><?php endif; ?>
    </section>
  </div>
</div>
