<?php
/**
 * aprobaciones.php — Bandeja de solicitudes por aprobar. Cada tarjeta muestra el saldo, quién más del área
 * falta esas fechas (tira de tres semanas) y quién lo reemplaza. Se aprueba sin recargar, con «Deshacer»;
 * para rechazar se pide el motivo en el panel «Revisar». También se pueden aprobar varias a la vez.
 */
$ids = array_column(colabs_visibles(), 'id');
$pend = array_values(array_filter(tabla('vacaciones'), fn($v) => $v['estado'] === 'Pendiente' && in_array($v['colabId'], $ids, true)));
usort($pend, fn($a, $b) => strcmp($a['desde'], $b['desde']));
$resueltas = array_values(array_filter(tabla('vacaciones'), fn($v) => in_array($v['estado'], ['Aprobada', 'Rechazada'], true) && in_array($v['colabId'], $ids, true) && $v['resuelto'] && $v['fecSol'] >= add_dias(HOY, -30)));
usort($resueltas, fn($a, $b) => strcmp($b['fecSol'], $a['fecSol']));

$pagina['titulo'] = 'Aprobaciones';
$pagina['sub'] = '<span data-contador>'.count($pend).'</span> por resolver · ordenadas por fecha de inicio';
?>
<div class="row g-4">
  <div class="col-xl-9">
    <section class="card mb-0" data-seleccion id="sol-lista">
      <div class="card-header">
        <div class="d-flex align-items-center gap-2"><?php if ($pend): ?><input class="form-check-input mt-0" type="checkbox" data-sel-todo id="sel-todo" aria-label="Seleccionar todas"><label for="sel-todo" class="small">Seleccionar todas</label><?php endif; ?></div>
        <span class="ctx">Las solicitudes que empiezan antes van primero</span>
      </div>
      <form class="bulk" hidden method="post" action="index.php?accion=aprobar_lote" data-ajax data-lote="#sol-lista"><?= campo_csrf() ?>
        <b data-sel-n></b><span class="sub2 d-inline">Se asigna a cada persona su reemplazo habitual.</span>
        <button class="btn btn-primary btn-sm ms-auto"><?= ic('check') ?> Aprobar seleccionadas</button>
        <button type="button" class="btn btn-ter btn-sm" data-bulk-limpiar>Quitar selección</button>
      </form>
      <div class="lista-sol">
      <?php foreach ($pend as $v): $c = por_id('colaboradores', $v['colabId']); $s = saldo_vac($c); $otros = ausentes_area($v);
        $pares = array_filter(activos(), fn($x) => $x['areaId'] === $c['areaId'] && $x['id'] !== $c['id']); $insuf = $v['tipo'] === 'Vacaciones' && $s['saldo'] < $v['dias']; ?>
        <article class="solicitud" data-item aria-label="Solicitud de <?= e(nomc($c)) ?>">
          <input class="form-check-input mt-2" type="checkbox" data-sel value="<?= $v['id'] ?>" aria-label="Seleccionar la solicitud de <?= e(nomc($c)) ?>">
          <?= avatar($c, 'l') ?>
          <div class="min-w-0">
            <div class="d-flex gap-2 align-items-baseline flex-wrap"><a class="nm fs-6" href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'vacaciones']) ?>"><?= e(nomc($c)) ?></a><span class="sub2 d-inline"><?= e(puesto($c)) ?> · <?= e(area($c)) ?></span></div>
            <div class="mt-1"><b><?= e($v['tipo']) ?></b> · <?= f($v['desde']) ?> al <?= f($v['hasta']) ?> · <?= pl($v['dias'], 'día', 'días') ?> <span class="sub2 d-inline">· empieza <?= e(hace($v['desde'])) ?> · pedida <?= e(hace($v['fecSol'])) ?></span></div>
            <div class="d-flex gap-2 flex-wrap mt-2">
              <?php if ($v['tipo'] === 'Vacaciones'): ?><?= tag($insuf ? 'Saldo insuficiente: '.$s['saldo'].' días' : 'Saldo '.$s['saldo'].' días · quedarían '.($s['saldo'] - $v['dias']), $insuf ? 'er' : 'gr') ?><?php endif; ?>
              <?= $otros ? tag(pl(count($otros), 'persona más ausente', 'personas más ausentes').' en el área', 'wa', 'También ausentes: '.implode(', ', array_map('nomc', $otros))) : tag('Nadie más ausente en el área', 'ok') ?>
              <?= $v['tipo'] === 'Descanso médico' ? tag('Con certificado adjunto', 'in') : '' ?>
            </div>
            <?= $v['obs'] ? '<p class="sub2 mt-2 mb-0">«'.e($v['obs']).'»</p>' : '' ?>
            <?= tira_area($v) ?>
          </div>
          <form class="acc-sol" method="post" action="index.php?accion=resolver" data-ajax data-quitar=".solicitud"><?= campo_csrf() ?>
            <input type="hidden" name="id" value="<?= $v['id'] ?>"><input type="hidden" name="enlinea" value="1"><input type="hidden" name="decision" value="aprobar">
            <?php if ($pares && $v['tipo'] === 'Vacaciones'): ?><label class="form-label small mb-1" for="r-<?= $v['id'] ?>">Lo reemplaza</label>
              <select class="form-select form-select-sm mb-2" id="r-<?= $v['id'] ?>" name="reemplazoId"><?= opciones($pares, $v['reemplazoId'] ?: $c['reemplazoId'], 'nomc', 'Sin reemplazo') ?></select><?php endif; ?>
            <div class="d-flex gap-2"><button type="button" class="btn btn-pel-sec btn-sm" data-panel="<?= e(http_build_query(['panel' => 'revisar', 'id' => $v['id']])) ?>">Rechazar</button><button class="btn btn-primary btn-sm flex-grow-1"><?= ic('check') ?> Aprobar</button></div>
          </form>
        </article>
      <?php endforeach; ?>
      </div>
      <div data-vacio-al-final <?= $pend ? 'hidden' : '' ?>><?= estado_vacio('No hay solicitudes por resolver', 'Cuando alguien de su equipo pida vacaciones o un permiso, aparecerá aquí.', '<a class="btn btn-sec btn-sm" href="'.url('vacaciones_calendario').'">Ver el calendario</a>') ?></div>
    </section>
  </div>
  <div class="col-xl-3">
    <section class="bloque-plano"><div class="cab"><h2>Resueltas recientemente</h2></div>
      <?php if ($resueltas): ?><ul class="lista lista-compacta">
        <?php foreach (array_slice($resueltas, 0, 8) as $v): $c = por_id('colaboradores', $v['colabId']); ?>
        <li><?= avatar($c, 's') ?><div class="min-w-0 flex-grow-1"><b class="d-block small text-truncate"><?= e(nomc($c)) ?></b><span class="sub2"><?= e($v['tipo']) ?> · <?= f($v['desde']) ?></span></div><?= estado($v['estado']) ?></li>
        <?php endforeach; ?></ul>
      <?php else: ?><p class="sub2">Sin resoluciones en los últimos 30 días.</p><?php endif; ?>
    </section>
  </div>
</div>
