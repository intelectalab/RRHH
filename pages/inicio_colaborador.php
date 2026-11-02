<?php
/**
 * inicio_colaborador.php — Inicio del perfil Colaborador: su horario y marcación de hoy,
 * asistencia del mes comparada con su área, saldo de vacaciones y contrato.
 */
$c = yo();
$pagina['titulo'] = 'Inicio';
if (!$c) { echo estado_vacio('Su usuario no está vinculado a un colaborador', 'Puede consultar los módulos de su perfil desde la barra superior.'); return; }
$pagina['titulo_html'] = '<span class="d-flex gap-3 align-items-center">'.avatar($c, 'l', false).'<span>Buenos días, '.e(explode(' ', $c['nombres'])[0]).'</span></span>';
$pagina['titulo'] = 'Inicio';
$pagina['sub'] = e(fecha_larga(HOY)).' · '.e(puesto($c)).' · '.e(sede($c));
$h = horario($c); $mh = marca($c['id'], HOY); $s = saldo_vac($c);
$ini = substr(HOY, 0, 7).'-01'; $r = resumen_asistencia($c, $ini, HOY);
$pares = array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && $x['areaId'] === $c['areaId']);
$tAreaProm = $pares ? array_sum(array_map(fn($x) => resumen_asistencia($x, $ini, HOY)['T'], $pares)) / count($pares) : 0;
$ms = marcas_de($c['id']); usort($ms, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
$prox = array_values(array_filter(tabla('vacaciones'), fn($v) => $v['colabId'] === $c['id'] && in_array($v['estado'], ['Aprobada','Pendiente'], true) && $v['hasta'] >= HOY));
?>
<div class="row g-4">
  <div class="col-lg-4">
    <section class="card destacada h-100 mb-0"><div class="card-header"><h2>Hoy</h2><span class="ctx"><?= f(HOY) ?></span></div><div class="card-body">
      <?= dato('Su horario', e($h['nombre']).' · '.$h['entrada'].' a '.$h['salida'], '') ?>
      <?= dato('Su marcación de entrada', $mh ? $mh['entrada'].' '.(estado_marca($mh)['tard'] ? tag(estado_marca($mh)['tard'].' min de tardanza', 'wa') : tag('Puntual', 'ok')) : 'Aún no registra entrada', '') ?>
      <a class="btn btn-sec btn-sm" href="<?= url('mis_marcaciones') ?>">Ver mis marcaciones</a>
    </div></section>
  </div>
  <div class="col-lg-4">
    <section class="card h-100 mb-0"><div class="card-header"><h2>Mi asistencia de <?= MESES[(int)substr(HOY, 5, 2) - 1] ?></h2></div><div class="card-body">
      <?= minicalendario($c, substr(HOY, 0, 7)) ?>
      <p class="sub2 mt-3 mb-0"><?= pl($r['T'], 'tardanza', 'tardanzas') ?> en el mes; el promedio de su área es <?= num($tAreaProm, 1) ?>. <?= pl($r['F'], 'falta', 'faltas') ?>.</p>
    </div></section>
  </div>
  <div class="col-lg-4">
    <section class="card h-100 mb-0"><div class="card-header"><h2>Mis vacaciones</h2><a href="<?= url('mis_vacaciones') ?>" class="small">Detalle</a></div><div class="card-body">
      <?= barra_saldo($s) ?>
      <?php foreach ($prox as $v): ?><p class="sub2 mt-2 mb-0"><?= e($v['tipo']) ?> del <?= f($v['desde']) ?> al <?= f($v['hasta']) ?> · <?= estado($v['estado']) ?></p><?php endforeach; ?>
      <button class="btn btn-primary btn-sm mt-3" data-panel="panel=solicitud&amp;id=<?= $c['id'] ?>"><?= ic('calendar-plus') ?> Solicitar vacaciones o permiso</button>
    </div></section>
  </div>
</div>
<section class="card mt-4"><div class="card-header"><h2>Últimas marcaciones</h2><span class="ctx"><?= e($h['nombre']) ?></span></div>
  <div class="table-responsive"><table class="table"><thead><tr><th>Fecha</th><th>Entrada</th><th>Salida</th><th class="num">Horas</th><th>Origen</th><th>Tardanza</th></tr></thead><tbody>
  <?php foreach (array_slice($ms, 0, 8) as $m): $em = estado_marca($m); ?>
    <tr><td><?= f($m['fecha']) ?> <span class="sub2"><?= e(hace($m['fecha'])) ?></span></td><td><?= $m['entrada'] ?></td><td><?= $m['salida'] ?: vacio_d() ?></td><td class="num"><?= $em['min'] !== null ? duracion($em['min']) : vacio_d() ?></td><td><?= e($m['origen']) ?></td><td><?= $em['tard'] ? tag($em['tard'].' min', 'wa') : vacio_d() ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
