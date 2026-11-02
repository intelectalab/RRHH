<?php
/**
 * organizacion.php — Tablas maestras: empresas y sedes (tarjetas con logo o foto), áreas, puestos,
 * centros de costo y horarios (lista con detalle a la derecha). También atiende «Asistencia › Horarios».
 * Los registros se archivan en vez de borrarse; no se archivan si tienen colaboradores activos.
 */
$deAsistencia = $p === 'horarios';
$k = $deAsistencia ? 'horarios' : ($_GET['tabla'] ?? 'empresas'); if (!isset(TABLAS_ORG[$k])) $k = 'empresas';
$T = TABLAS_ORG[$k]; $editar = puede('organizacion.editar');
$verArch = !empty($_GET['archivados']);
$regs = array_values(array_filter(tabla($k), fn($r) => $verArch || $r['activo']));
$nArch = count(array_filter(tabla($k), fn($r) => !$r['activo']));
$act = array_values(array_filter(tabla('colaboradores'), fn($c) => $c['estado'] === 'Activo'));
$de = fn(int $id) => array_values(array_filter($act, fn($c) => $c[CAMPO_COLAB[$k]] === $id));
$sel = (int)($_GET['reg'] ?? ($regs[0]['id'] ?? 0)); $r = por_id($k, $sel);
$cn = $T['campos'][0][0];

if ($deAsistencia) {
    $pagina['titulo'] = 'Asistencia';
    $pagina['pestanas'] = pestanas([[url('asistencia'), 'Del día', false], [url('asistencia_mensual'), 'Tareo mensual', false], [url('horarios'), 'Horarios', true]]);
} else {
    $pagina['titulo'] = 'Organización';
    $pagina['pestanas'] = pestanas(array_map(fn($t, $x) => [url('organizacion', ['tabla' => $t]), $x['t'], $t === $k, count(array_filter(tabla($t), fn($y) => $y['activo']))], array_keys(TABLAS_ORG), TABLAS_ORG));
}
$pagina['sub'] = pl(count(array_filter($regs, fn($x) => $x['activo'])), 'registro activo', 'registros activos').($nArch ? ' · '.$nArch.' archivados' : '');
$pagina['acciones'] = ($nArch ? '<a class="btn btn-ter" href="'.url($p, array_filter(['tabla' => $deAsistencia ? '' : $k, 'archivados' => $verArch ? '' : 1])).'">'.($verArch ? 'Ocultar archivados' : 'Mostrar archivados').'</a>' : '')
    .($editar ? btn_panel('registro', ['tabla' => $k], ic('plus').' '.e($T['nuevo']), 'btn btn-primary') : '');
$botones = function (array $x) use ($k, $editar) {
    if (!$editar) return '';
    return btn_panel('registro', ['tabla' => $k, 'id' => $x['id']], ic('pencil-simple').' Editar', 'btn btn-ter btn-sm')
        .($x['activo'] ? btn_panel('confirmar', ['que' => 'archivar', 'tabla' => $k, 'id' => $x['id']], ic('archive').' Archivar', 'btn btn-ter btn-sm') : btn_panel('confirmar', ['que' => 'archivar', 'tabla' => $k, 'id' => $x['id']], ic('arrow-counter-clockwise').' Restaurar', 'btn btn-ter btn-sm'));
};
?>
<?php if (!$regs): ?>
  <section class="card"><?= estado_vacio('Aún no hay '.mb_strtolower($T['t']), 'Registre el primero para poder usarlo en las fichas.', $editar ? btn_panel('registro', ['tabla' => $k], e($T['nuevo']), 'btn btn-primary btn-sm') : '') ?></section>

<?php elseif ($k === 'empresas'): ?>
<div class="lugar-grid">
  <?php foreach ($regs as $x): $gente = $de($x['id']); $sedes = array_filter(tabla('sedes'), fn($s) => $s['empresaId'] === $x['id'] && $s['activo']); ?>
  <article class="lugar <?= $x['activo'] ? '' : 'archivado' ?>">
    <div class="empresa-cab"><?= logo_empresa($x, 'lg') ?><div class="min-w-0"><h3 class="h2 mb-0"><?= e($x['razon']) ?></h3><span class="sub2">RUC <?= e($x['ruc']) ?><?= $x['activo'] ? '' : ' · archivada' ?></span></div></div>
    <div class="cuerpo pt-0">
      <span class="sub2"><?= ic('map-pin') ?> <?= e($x['direccion'] ?: 'Sin dirección registrada') ?></span>
      <dl class="resumen"><dt>Colaboradores activos</dt><dd><?= count($gente) ?></dd><dt>Sedes</dt><dd><?= count($sedes) ?></dd>
        <dt>Contratos a plazo</dt><dd><?= count(array_filter($gente, fn($c) => $c['tipoContrato'] !== 'Indeterminado')) ?></dd></dl>
      <?= grupo_fotos($gente, 8) ?>
    </div>
    <div class="pie"><a class="small" href="<?= url('organizacion', ['tabla' => 'sedes']) ?>">Ver sedes</a><span><?= $botones($x) ?></span></div>
  </article>
  <?php endforeach; ?>
</div>

<?php elseif ($k === 'sedes'): ?>
<div class="lugar-grid">
  <?php foreach ($regs as $x): $gente = $de($x['id']); $resp = por_id('colaboradores', (int)($x['responsableId'] ?? 0)); $emp = por_id('empresas', $x['empresaId']);
    $hoyP = array_filter($gente, fn($c) => laborable($c, HOY) && !ausencia($c, HOY)); $pres = count(array_filter($hoyP, fn($c) => in_array(codigo_dia($c, HOY), ['A', 'T'], true))); ?>
  <article class="lugar <?= $x['activo'] ? '' : 'archivado' ?>">
    <?= foto_sede($x) ?>
    <div class="cuerpo">
      <div class="d-flex justify-content-between gap-2 align-items-start"><div class="min-w-0"><h3><?= e($x['nombre']) ?></h3><span class="sub2"><?= e($x['ciudad']) ?> · <?= e($x['direccion']) ?></span></div><?= logo_empresa($emp, 'sm') ?></div>
      <dl class="resumen"><dt>Colaboradores activos</dt><dd><?= count($gente) ?></dd><dt>Presentes hoy</dt><dd><?= $hoyP ? $pres.' de '.count($hoyP) : vacio_d() ?></dd></dl>
      <?php if ($resp): ?><a class="persona-mini" href="<?= url('colaborador', ['id' => $resp['id']]) ?>"><?= avatar($resp, 'm') ?><span><span class="sub2">Responsable</span><b><?= e(nomc($resp)) ?></b></span></a><?php endif; ?>
    </div>
    <div class="pie"><a class="small" href="<?= url('personal', ['sede' => $x['id']]) ?>">Ver personal</a><span><?= $botones($x) ?></span></div>
  </article>
  <?php endforeach; ?>
</div>

<?php else: ?>
<div class="row g-4">
  <div class="col-lg-5">
    <section class="card mb-0">
      <div class="table-responsive"><table class="table mb-0">
        <thead><tr><?php foreach (array_slice($T['campos'], 0, 2) as [$c0, $l0]): ?><th><?= e($l0) ?></th><?php endforeach; ?><th class="num">Activos</th></tr></thead>
        <tbody>
        <?php foreach ($regs as $x): $n = count($de($x['id'])); ?>
          <tr data-href="<?= url($p, array_filter(['tabla' => $deAsistencia ? '' : $k, 'reg' => $x['id'], 'archivados' => $verArch ? 1 : ''])) ?>" class="<?= $x['id'] === $sel ? 'sel' : '' ?> <?= $x['activo'] ? '' : 'text-secondary' ?>" <?= $x['id'] === $sel ? 'aria-current="true"' : '' ?>>
            <?php foreach (array_slice($T['campos'], 0, 2) as [$c0, $l0, $t0]): ?>
            <td><?= $t0 === 'areas' ? e(por_id('areas', (int)$x[$c0])['nombre'] ?? '—') : ($t0 === 'time' ? e($x[$c0]) : '<b>'.e($x[$c0]).'</b>') ?><?= !$x['activo'] && $c0 === $cn ? ' '.tag('Archivado') : '' ?></td>
            <?php endforeach; ?>
            <td class="num"><?= $n ?: vacio_d() ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
  </div>
  <div class="col-lg-7">
    <?php if ($r): $gente = $de($r['id']); ?>
    <section class="card mb-0">
      <div class="card-header"><h2><?= e($r[$cn]) ?></h2><span><?= $botones($r) ?></span></div>
      <div class="card-body">
        <?php if ($k === 'areas'): $sub = array_filter(tabla('areas'), fn($a) => $a['padreId'] === $r['id'] && $a['activo']); $pu = array_filter(tabla('puestos'), fn($x) => $x['areaId'] === $r['id'] && $x['activo']);
          $jefes = array_filter($gente, fn($c) => !in_array($c['jefeId'], array_column($gente, 'id'), true)); ?>
          <dl class="resumen mb-3"><dt>Depende de</dt><dd><?= e(por_id('areas', $r['padreId'])['nombre'] ?? 'Ninguna (nivel superior)') ?></dd><dt>Subáreas</dt><dd><?= $sub ? e(implode(', ', array_column($sub, 'nombre'))) : vacio_d() ?></dd>
            <dt>Puestos</dt><dd><?= count($pu) ?></dd><dt>Colaboradores activos</dt><dd><?= count($gente) ?></dd></dl>
          <?php if ($jefes): ?><h3 class="h-sec">Jefatura</h3><?php foreach (array_slice($jefes, 0, 2) as $j): ?><a class="persona-mini mb-2" href="<?= url('colaborador', ['id' => $j['id']]) ?>"><?= avatar($j, 'm') ?><span><b><?= e(nomc($j)) ?></b><span class="sub2"><?= e(puesto($j)) ?></span></span></a><?php endforeach; ?><?php endif; ?>
          <?php if ($pu): ?><h3 class="h-sec mt-3">Personas por puesto</h3><?= barras_h(array_map(fn($x) => ['et' => $x['nombre'], 'v' => count(array_filter($gente, fn($c) => $c['puestoId'] === $x['id']))], array_values($pu))) ?><?php endif; ?>
        <?php elseif ($k === 'puestos'): $suel = array_column($gente, 'sueldo'); ?>
          <dl class="resumen mb-3"><dt>Área</dt><dd><?= e(por_id('areas', $r['areaId'])['nombre'] ?? '') ?></dd><dt>Colaboradores activos</dt><dd><?= count($gente) ?></dd>
            <?php if (puede('personal.sueldo') && $suel): ?><dt>Sueldo promedio</dt><dd><?= soles(array_sum($suel) / count($suel)) ?></dd><dt>Rango</dt><dd><?= soles(min($suel)) ?> – <?= soles(max($suel)) ?></dd><?php endif; ?></dl>
        <?php elseif ($k === 'horarios'):
          $mes = substr(HOY, 0, 7); $p100 = []; foreach ($gente as $c) { $ra = resumen_asistencia($c, max("$mes-01", $c['fecIngreso']), HOY); if ($ra['prog']) $p100[] = $ra['A'] / $ra['prog'] * 100; } ?>
          <div class="d-flex gap-4 flex-wrap mb-3">
            <div><span class="sub2">Entrada</span><span class="cifra-m"><?= e($r['entrada']) ?></span></div><div><span class="sub2">Salida</span><span class="cifra-m"><?= e($r['salida']) ?></span></div>
            <div><span class="sub2">Jornada</span><span class="cifra-m"><?= duracion(min_a_hora($r['salida']) - min_a_hora($r['entrada'])) ?></span></div><div><span class="sub2">Tolerancia</span><span class="cifra-m"><?= (int)$r['tolerancia'] ?> min</span></div>
          </div>
          <div class="dias-sem mb-3"><?php foreach (['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $i => $d): $on = in_array((string)(($i + 1) % 7), explode(',', $r['dias']), true); ?><span class="<?= $on ? 'on' : '' ?>" aria-label="<?= e(DIAS[($i + 1) % 7]).($on ? ' laborable' : ' libre') ?>"><?= $d ?></span><?php endforeach; ?></div>
          <?php if ($p100): $pp = array_sum($p100) / count($p100); ?><div class="comparar"><span>Puntualidad del mes</span><?= medidor($pp, $pp < 85 ? 'wa' : 'g1') ?><span class="num"><?= round($pp) ?> %</span></div><?php endif; ?>
        <?php elseif ($k === 'ccs'): ?>
          <dl class="resumen mb-3"><dt>Código</dt><dd><?= e($r['codigo']) ?></dd><dt>Colaboradores activos</dt><dd><?= count($gente) ?></dd>
            <?php if (puede('personal.sueldo') && $gente): ?><dt>Planilla mensual</dt><dd><?= soles(array_sum(array_column($gente, 'sueldo'))) ?></dd><?php endif; ?></dl>
        <?php endif; ?>
        <h3 class="h-sec mt-3">Colaboradores · <?= count($gente) ?></h3>
        <?php if ($gente): usort($gente, fn($a, $b) => strcmp(nom($a), nom($b))); ?>
          <ul class="lista lista-compacta"><?php foreach (array_slice($gente, 0, 8) as $c): ?><li><?= avatar($c) ?><div class="min-w-0 flex-grow-1"><a class="nm" href="<?= url('colaborador', ['id' => $c['id']]) ?>"><?= e(nom($c)) ?></a><span class="sub2"><?= e(puesto($c)) ?></span></div></li><?php endforeach; ?></ul>
          <?php if (count($gente) > 8): ?><div class="d-flex align-items-center gap-2 mt-2"><?= grupo_fotos(array_slice($gente, 8), 6) ?><span class="sub2 d-inline">y <?= count($gente) - 8 ?> más</span></div><?php endif; ?>
        <?php else: ?><p class="sub2 mb-0">Ningún colaborador activo. <?= $r['activo'] && $editar ? 'Puede archivarlo si ya no se usa.' : '' ?></p><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
