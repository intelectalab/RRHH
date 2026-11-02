<?php
/**
 * organigrama.php — Organigrama por jefaturas con fotos. Cada tarjeta muestra a la jefatura,
 * su área y, en miniatura, a las personas a su cargo que no tienen equipo propio.
 */
$act = activos(); $ids = array_column($act, 'id');
$hijos = []; foreach ($act as $c) $hijos[in_array($c['jefeId'], $ids, true) ? $c['jefeId'] : 0][] = $c;
foreach ($hijos as &$l) usort($l, fn($a, $b) => strcmp(area($a).nom($a), area($b).nom($b))); unset($l);
$areaSel = (int)($_GET['area'] ?? 0);

$pagina['titulo'] = 'Personal';
$pagina['sub'] = 'Organigrama por jefaturas · '.pl(count($act), 'persona activa', 'personas activas');
$pagina['pestanas'] = pestanas([[url('personal'), 'Colaboradores', false], [url('organigrama'), 'Organigrama', true], [url('contratos'), 'Contratos', false], [url('cumpleanos'), 'Cumpleaños y aniversarios', false]]);
$pagina['acciones'] = '<button type="button" class="btn btn-sec" data-imprimir>'.ic('printer').' Imprimir</button>';

/* Nodo: tarjeta + subárbol de las jefaturas a su cargo */
$nodo = function (array $c) use (&$nodo, $hijos, $areaSel): string {
    $sub = $hijos[$c['id']] ?? [];
    $jefes = array_values(array_filter($sub, fn($x) => !empty($hijos[$x['id']])));
    $resto = array_values(array_filter($sub, fn($x) => empty($hijos[$x['id']])));
    $atenuar = $areaSel && !in_array($areaSel, array_merge([$c['areaId']], array_column($sub, 'areaId')), true);
    $h = '<li><div class="ocard'.($atenuar ? ' atenuada' : '').'">'.avatar($c, 'l').'<span class="ar">'.e(area($c)).'</span><a href="'.url('colaborador', ['id' => $c['id']]).'">'.e(nomc($c)).'</a><small>'.e(puesto($c)).'</small>';
    if ($resto) $h .= '<div class="cn"><div class="mb-1">'.grupo_fotos($resto, 6).'</div>'.pl(count($sub), 'persona a cargo', 'personas a cargo').'</div>';
    elseif ($sub) $h .= '<div class="cn">'.pl(count($sub), 'persona a cargo', 'personas a cargo').'</div>';
    $h .= '</div>';
    if ($jefes) { $h .= '<ul>'; foreach ($jefes as $j) $h .= $nodo($j); $h .= '</ul>'; }
    return $h.'</li>';
};
$raices = $hijos[0] ?? [];
$conEquipo = array_values(array_filter($raices, fn($x) => !empty($hijos[$x['id']])));
$sueltos = array_values(array_filter($raices, fn($x) => empty($hijos[$x['id']])));
?>
<section class="card">
  <div class="card-header"><h2>Estructura</h2>
    <form class="d-flex gap-2 align-items-center" action="index.php"><input type="hidden" name="p" value="organigrama">
      <label class="sub2 d-inline" for="o-area">Resaltar área</label>
      <select class="form-select form-select-sm" id="o-area" name="area" onchange="this.form.submit()" style="width:auto"><option value="">Todas</option><?= opciones(array_filter(tabla('areas'), fn($a) => $a['activo']), $areaSel, fn($a) => $a['nombre']) ?></select>
    </form>
  </div>
  <?php if (!$act): ?><?= estado_vacio('No hay colaboradores activos') ?>
  <?php else: ?>
  <div class="org"><ul><?php foreach ($conEquipo as $r) echo $nodo($r); ?></ul></div>
  <?php if ($sueltos): ?><div class="card-footer"><span class="sub2 d-inline me-2">Sin jefe directo registrado:</span><?= grupo_fotos($sueltos, 12) ?></div><?php endif; ?>
  <?php endif; ?>
</section>
