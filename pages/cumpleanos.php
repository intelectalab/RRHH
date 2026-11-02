<?php
/**
 * cumpleanos.php — Cumpleaños y aniversarios de trabajo del mes, con navegación entre meses.
 */
$m = max(1, min(12, (int)($_GET['mes'] ?? substr(HOY, 5, 2))));
$act = activos(); $anio = (int)substr(HOY, 0, 4);
$fecha = fn(string $iso) => sprintf('%04d-%02d-%s', $anio, $m, substr($iso, 8, 2));
$cumple = array_values(array_filter($act, fn($c) => (int)substr($c['fecNac'], 5, 2) === $m)); usort($cumple, fn($a, $b) => strcmp(substr($a['fecNac'], 8), substr($b['fecNac'], 8)));
$aniv = array_values(array_filter($act, fn($c) => (int)substr($c['fecIngreso'], 5, 2) === $m && (int)substr($c['fecIngreso'], 0, 4) < $anio)); usort($aniv, fn($a, $b) => strcmp(substr($a['fecIngreso'], 8), substr($b['fecIngreso'], 8)));

$pagina['titulo'] = 'Personal';
$pagina['sub'] = 'Cumpleaños y aniversarios de '.mes_nombre($m, false);
$pagina['pestanas'] = pestanas([[url('personal'), 'Colaboradores', false], [url('organigrama'), 'Organigrama', false], [url('contratos'), 'Contratos', false], [url('cumpleanos'), 'Cumpleaños y aniversarios', true]]);
$pagina['acciones'] = '<div class="mes-nav"><a class="btn btn-sec btn-sm btn-ico" href="'.url('cumpleanos', ['mes' => $m === 1 ? 12 : $m - 1]).'" aria-label="Mes anterior">'.ic('caret-left').'</a><b>'.mes_nombre($m).'</b><a class="btn btn-sec btn-sm btn-ico" href="'.url('cumpleanos', ['mes' => $m === 12 ? 1 : $m + 1]).'" aria-label="Mes siguiente">'.ic('caret-right').'</a></div>';

$fila = function (array $c, string $x, string $txt) {
    return '<li class="'.($x === HOY ? 'es-hoy' : '').'"><span class="dia-num"><b>'.(int)substr($x, 8).'</b><small>'.e(mb_substr(DIAS[dia_sem($x)], 0, 3)).'</small></span>'.avatar($c, 'm')
        .'<div class="min-w-0 flex-grow-1"><a class="nm" href="'.url('colaborador', ['id' => $c['id']]).'">'.e(nom($c)).'</a><span class="sub2">'.e(puesto($c)).' · '.e(area($c)).'</span></div>'
        .'<div class="text-end"><b class="d-block small">'.$txt.'</b><span class="dia-ev '.($x === HOY ? 'hoy' : '').'">'.e($x === HOY ? 'Hoy' : ucfirst(hace($x))).'</span></div></li>';
};
?>
<div class="row g-4">
  <div class="col-lg-6">
    <section class="card mb-0 h-100"><div class="card-header"><h2><?= ic('cake') ?> Cumpleaños</h2><span class="ctx"><?= count($cumple) ?></span></div>
      <?php if ($cumple): ?><ul class="lista"><?php foreach ($cumple as $c) { $x = $fecha($c['fecNac']); echo $fila($c, $x, ($anio - (int)substr($c['fecNac'], 0, 4)).' años'); } ?></ul>
      <?php else: ?><?= estado_vacio('Nadie cumple años en '.mes_nombre($m, false)) ?><?php endif; ?></section>
  </div>
  <div class="col-lg-6">
    <section class="card mb-0 h-100"><div class="card-header"><h2><?= ic('medal') ?> Aniversarios en la empresa</h2><span class="ctx"><?= count($aniv) ?></span></div>
      <?php if ($aniv): ?><ul class="lista"><?php foreach ($aniv as $c) { $x = $fecha($c['fecIngreso']); $n = $anio - (int)substr($c['fecIngreso'], 0, 4); echo $fila($c, $x, pl($n, 'año', 'años')); } ?></ul>
      <?php else: ?><?= estado_vacio('No hay aniversarios en '.mes_nombre($m, false)) ?><?php endif; ?></section>
  </div>
</div>
