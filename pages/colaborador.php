<?php
/**
 * colaborador.php — Ficha del colaborador. También atiende «Mi ficha» y «Mis marcaciones»
 * (la ficha de la persona que ingresó). Pestañas en el navegador: resumen, datos personales,
 * datos laborales, contrato, historial, asistencia, vacaciones y pensión/banco.
 */
$propia = in_array($p, ['mi_ficha', 'mis_marcaciones'], true);
$c = $propia ? yo() : por_id('colaboradores', (int)($_GET['id'] ?? 0));
if (!$c || (!$propia && !puede_ver_colab($c))) {
    $pagina['titulo'] = 'Ficha no disponible';
    echo estado_vacio($propia ? 'Su usuario no está vinculado a un colaborador' : 'No se encontró al colaborador', $propia ? 'Pida al área de RR. HH. que vincule su usuario con su ficha.' : 'Puede que no exista o que esté fuera del alcance de su perfil.', puede('personal.ver') ? '<a class="btn btn-sec btn-sm" href="'.url('personal').'">Volver a Personal</a>' : '');
    return;
}
$esYo = $c['id'] === (yo()['id'] ?? 0);
$editar = puede('personal.editar'); $verSueldo = puede('personal.sueldo') || $esYo;
$tabs = ['resumen' => 'Resumen', 'personales' => 'Datos personales', 'laboral' => 'Datos laborales', 'contrato' => 'Contrato y documentos', 'historial' => 'Historial', 'asistencia' => 'Asistencia', 'vacaciones' => 'Vacaciones', 'pension' => 'Pensión y banco'];
$tab = $_GET['tab'] ?? ($p === 'mis_marcaciones' ? 'asistencia' : 'resumen'); if (!isset($tabs[$tab])) $tab = 'resumen';

/* ---------- datos derivados ---------- */
$jefe = por_id('colaboradores', (int)$c['jefeId']); $reemp = por_id('colaboradores', (int)($c['reemplazoId'] ?? 0));
$equipo = array_values(array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && $x['areaId'] === $c['areaId'] && $x['id'] !== $c['id']));
$aCargo = array_values(array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && $x['jefeId'] === $c['id']));
$companeros = array_values(array_filter($equipo, fn($x) => $x['jefeId'] === $c['jefeId'] && $x['id'] !== $c['jefeId']));
$s = saldo_vac($c); $h = horario($c);
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : substr(HOY, 0, 7);
if ($ym > substr(HOY, 0, 7)) $ym = substr(HOY, 0, 7);
$ini = "$ym-01"; $fin = min(fin_mes($ym), HOY);
$ra = resumen_asistencia($c, $ini, $fin);
$punt = $ra['prog'] ? $ra['A'] / $ra['prog'] * 100 : 0;
/* Promedios del área en el mismo mes (para dar contexto) */
$pa = ['T' => 0, 'F' => 0, 'p' => 0, 'n' => 0, 'saldo' => 0];
foreach ($equipo as $x) { $r = resumen_asistencia($x, max($ini, $x['fecIngreso']), $fin); if (!$r['prog']) continue; $pa['T'] += $r['T']; $pa['F'] += $r['F']; $pa['p'] += $r['A'] / $r['prog'] * 100; $pa['n']++; }
foreach ($equipo as $x) $pa['saldo'] += saldo_vac($x)['saldo'];
$promT = $pa['n'] ? $pa['T'] / $pa['n'] : 0; $promP = $pa['n'] ? $pa['p'] / $pa['n'] : 0; $promS = $equipo ? $pa['saldo'] / count($equipo) : 0;
$hist = array_values(array_filter(tabla('historial'), fn($x) => $x['colabId'] === $c['id']));
usort($hist, fn($a, $b) => strcmp($b['fecha'], $a['fecha']) ?: $b['id'] - $a['id']);
$edad = intdiv(dif_dias($c['fecNac'], HOY), 365);
$vacs = array_values(array_filter(tabla('vacaciones'), fn($v) => $v['colabId'] === $c['id'])); usort($vacs, fn($a, $b) => strcmp($b['desde'], $a['desde']));
$prox = array_values(array_filter($vacs, fn($v) => in_array($v['estado'], ['Aprobada', 'Pendiente'], true) && $v['hasta'] >= HOY)); usort($prox, fn($a, $b) => strcmp($a['desde'], $b['desde']));
$ausHoy = ausencia($c, HOY);
$contexto = function (float $dif, string $unidad, string $sing, string $plu, bool $masEsMalo = true): string {
    if (abs($dif) < .5) return 'En línea con el promedio del área';
    $n = (int)round(abs($dif)); return '<b class="'.(($dif > 0) === $masEsMalo ? 'text-danger' : 'text-success').'">'.pl($n, $sing, $plu).' '.($dif > 0 ? 'más' : 'menos').'</b> que el promedio del área';
};

/* Sueldo mes a mes (desde el ingreso o los últimos 6 años) */
$cambiosS = array_reverse(array_values(array_filter($hist, fn($x) => $x['tipo'] === 'Cambio de sueldo')));
$aNum = fn($t) => (float)str_replace([',', 'S/', ' '], '', $t);
$sueldoEn = function (string $x) use ($cambiosS, $aNum, $c) {
    $v = $cambiosS ? $aNum($cambiosS[0]['antes']) : (float)$c['sueldo'];
    foreach ($cambiosS as $k) if ($k['fecha'] <= $x) $v = $aNum($k['despues']);
    return $v;
};
$serieS = [];
if ($verSueldo) {
    $desde = max(substr($c['fecIngreso'], 0, 7), substr(add_meses(HOY, -71), 0, 7));
    for ($m = $desde; $m <= substr(HOY, 0, 7); $m = substr(add_meses("$m-15", 1), 0, 7)) {
        $v = $sueldoEn(fin_mes($m)); $cambio = (bool)array_filter($cambiosS, fn($k) => substr($k['fecha'], 0, 7) === $m);
        $serieS[] = ['et' => MES3[(int)substr($m, 5) - 1].' '.substr($m, 2, 2), 'v' => $v, 'tip' => mes_nombre((int)substr($m, 5)).' '.substr($m, 0, 4).': '.soles($v).($cambio ? ' · cambio de sueldo' : ''), 'marca' => $cambio];
    }
    $sueldoIni = $cambiosS ? $aNum($cambiosS[0]['antes']) : (float)$c['sueldo'];
    $mismos = array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && $x['puestoId'] === $c['puestoId']);
}

/* Próximas fechas de la persona */
$fechas = [];
if ($c['estado'] === 'Activo') {
    $fechas[] = [cumple_proximo($c['fecNac']), 'Cumpleaños', 'Cumple '.($edad + (cumple_proximo($c['fecNac']) === HOY ? 0 : 1)).' años', 'cake'];
    $an = cumple_proximo($c['fecIngreso']); $fechas[] = [$an, 'Aniversario en la empresa', pl((int)substr($an, 0, 4) - (int)substr($c['fecIngreso'], 0, 4), 'año', 'años'), 'medal'];
    if ($c['finContrato']) $fechas[] = [$c['finContrato'], $c['finContrato'] < HOY ? 'Contrato vencido' : 'Fin de contrato', e($c['tipoContrato']), 'file-text'];
    if ($s['viejo']) $fechas[] = [$s['viejo']['vence'], 'Vence periodo vacacional', 'Periodo '.substr($s['viejo']['ini'], 0, 4).'–'.substr($s['viejo']['fin'], 0, 4).' · '.($s['viejo']['dias'] - $s['viejo']['usados']).' días sin gozar', 'airplane-tilt'];
    foreach (array_slice($prox, 0, 1) as $v) $fechas[] = [$v['desde'], $v['tipo'].' '.($v['estado'] === 'Pendiente' ? 'por aprobar' : 'programadas'), pl($v['dias'], 'día', 'días'), 'calendar-check'];
}
usort($fechas, fn($a, $b) => strcmp($a[0], $b[0]));
ob_start(); ?>
<section class="card mb-0 h-100" aria-labelledby="r-fe">
  <div class="card-header"><h2 id="r-fe">Próximas fechas</h2></div>
  <?php if ($fechas): ?><ul class="lista">
    <?php foreach ($fechas as [$x, $t, $d, $i]): ?>
    <li><span class="dia-num"><b><?= (int)substr($x, 8) ?></b><small><?= MES3[(int)substr($x, 5, 2) - 1] ?></small></span><div class="min-w-0 flex-grow-1"><b class="d-block"><?= ic($i) ?> <?= e($t) ?></b><span class="sub2"><?= $d ?></span></div><span class="dia-ev <?= $x === HOY ? 'hoy' : '' ?> <?= $x < HOY ? 'vencido' : '' ?>"><?= e(ucfirst(hace($x))) ?></span></li>
    <?php endforeach; ?></ul>
  <?php else: ?><?= estado_vacio('Sin fechas próximas') ?><?php endif; ?>
</section>
<?php $fechasHtml = ob_get_clean();

/* ---------- encabezado de la ficha ---------- */
$acciones = '';
if ($editar && !$esYo) {
    if ($c['estado'] === 'Activo') $acciones .= btn_panel('movimiento', ['id' => $c['id']], ic('arrows-left-right').' Registrar movimiento', 'btn btn-primary');
    $acciones .= '<a class="btn btn-sec" href="'.url('colaborador_form', ['id' => $c['id']]).'">'.ic('pencil-simple').' Editar</a>';
    $acciones .= '<div class="dropdown"><button class="btn btn-sec btn-ico" data-bs-toggle="dropdown" aria-label="Más acciones" aria-expanded="false">'.ic('dots-three').'</button><ul class="dropdown-menu dropdown-menu-end">'
        .($c['estado'] === 'Activo' ? '<li><a class="dropdown-item" href="index.php?imprimir=constancia&amp;id='.$c['id'].'" target="_blank">'.ic('file-text').' Emitir constancia de trabajo</a></li>'.item_panel('solicitud', ['id' => $c['id']], ic('airplane-tilt').' Registrar ausencia o vacaciones') : '')
        .($c['estado'] === 'Activo' && $c['tipoContrato'] !== 'Indeterminado' ? item_panel('renovar', ['id' => $c['id']], ic('arrows-clockwise').' Renovar contrato') : '')
        .($c['estado'] === 'Activo' ? '<li><hr class="dropdown-divider"></li>'.item_panel('cese', ['id' => $c['id']], ic('user-minus').' Registrar cese', 'text-danger') : item_panel('reingreso', ['id' => $c['id']], ic('user-plus').' Registrar reingreso'))
        .'</ul></div>';
} elseif ($esYo && $c['estado'] === 'Activo') $acciones .= btn_panel('solicitud', ['id' => $c['id']], ic('airplane-tilt').' Solicitar vacaciones o permiso', 'btn btn-primary');

$tiraFicha = [
    ['Antigüedad', e(antiguedad($c['fecIngreso'], $c['fecCese'] ?: null)), 'Desde el '.f($c['fecIngreso'])],
    ['Contrato', e($c['tipoContrato']), $c['tipoContrato'] === 'Indeterminado' ? 'Sin fecha de término' : ($c['finContrato'] < HOY ? '<span class="text-danger fw-bold">Venció '.e(hace($c['finContrato'])).'</span>' : 'Vence '.e(hace($c['finContrato'])).' ('.f($c['finContrato']).')')],
    ['Asistencia en '.mes_nombre((int)substr(HOY, 5, 2), false), $ra['prog'] ? round($punt).' %' : vacio_d(), pl($ra['T'], 'tardanza', 'tardanzas').' · '.pl($ra['F'], 'falta', 'faltas')],
    ['Vacaciones disponibles', pl($s['saldo'], 'día', 'días'), $s['prog'] ? $s['prog'].' programados' : ($s['pend'] ? $s['pend'].' por aprobar' : 'Ninguna programada')],
    ['Jefe directo', $jefe ? '<a class="nm" href="'.url('colaborador', ['id' => $jefe['id']]).'">'.e(nomc($jefe)).'</a>' : vacio_d(), $jefe ? e(puesto($jefe)) : ''],
];
ob_start(); ?>
<div class="ficha-cab"><div class="in">
  <?php if (puede('personal.ver') && !$propia): ?><nav aria-label="Ruta"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= url('personal') ?>">Personal</a></li><li class="breadcrumb-item active" aria-current="page"><?= e(nom($c)) ?></li></ol></nav><?php endif; ?>
  <div class="ficha-id">
    <?php if ($editar): ?>
    <div class="avatar-cambiar position-relative d-inline-block" style="cursor:pointer;" data-panel="panel=cambiar_foto&amp;id=<?= $c['id'] ?>" title="Cambiar foto de <?= e(nomc($c)) ?>">
      <?= avatar($c, 'xl', false) ?>
      <span class="position-absolute bottom-0 start-0 w-100 text-center py-1 text-white small" style="background:rgba(0,0,0,0.65);border-bottom-left-radius:50px;border-bottom-right-radius:50px;font-size:10px;"><?= ic('camera') ?> Cambiar</span>
    </div>
    <?php else: ?>
      <?= avatar($c, 'xl', false) ?>
    <?php endif; ?>
    <div class="min-w-0 flex-grow-1 pb-1">
      <div class="d-flex gap-2 align-items-center flex-wrap"><h1><?= e($c['nombres'].' '.$c['apPat'].' '.$c['apMat']) ?></h1>
        <?= $c['estado'] === 'Cesado' ? estado('Cesado', 'Cesado el '.f($c['fecCese'])) : ($ausHoy ? tag($ausHoy['tipo'].' hasta el '.f($ausHoy['hasta']), 'in') : '') ?>
        <?= incompleta($c) ? tag('Ficha incompleta', 'wa') : '' ?></div>
      <div class="meta">
        <span><?= ic('briefcase') ?><?= e(puesto($c)) ?></span><span><?= ic('tree-structure') ?><?= e(area($c)) ?></span>
        <span><?= ic('map-pin') ?><?= e(sede($c)) ?></span><span><?= ic('identification-badge') ?><?= e($c['codigo']) ?> · <?= e($c['tipoDoc']) ?> <?= e($c['nroDoc']) ?></span>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap pb-1"><?= $acciones ?></div>
  </div>
  <div class="fstrip">
    <?php foreach ($tiraFicha as [$l, $v, $sub]): ?><div><label><?= e($l) ?></label><b><?= $v ?></b><span class="sub2"><?= $sub ?></span></div><?php endforeach; ?>
  </div>
  <div class="ftabs" role="tablist" data-tabs aria-label="Secciones de la ficha">
    <?php foreach ($tabs as $k => $t): ?><button type="button" role="tab" data-tab="<?= $k ?>" id="bt-<?= $k ?>" aria-controls="tab-<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" tabindex="<?= $tab === $k ? 0 : -1 ?>"><?= e($t) ?></button><?php endforeach; ?>
  </div>
</div></div>
<?php
$pagina['encabezado_html'] = ob_get_clean();
$pagina['titulo'] = nom($c);
$panel = fn(string $k) => 'id="tab-'.$k.'" class="panel-tab" role="tabpanel" aria-labelledby="bt-'.$k.'" '.($tab === $k ? '' : 'hidden');
?>

<!-- ============ Resumen ============ -->
<div <?= $panel('resumen') ?>>
  <div class="row g-4">
    <div class="col-xl-8">
      <section class="card mb-0 h-100" aria-labelledby="r-as">
        <div class="card-header"><h2 id="r-as">Asistencia de <?= mes_nombre((int)substr(HOY, 5, 2), false) ?></h2><a href="#" class="small" data-ir-tab="asistencia">Ver marcaciones</a></div>
        <div class="card-body">
          <div class="row g-4 align-items-start">
            <div class="col-md-6"><?= minicalendario($c, substr(HOY, 0, 7)) ?>
              <div class="leyenda mt-2"><span><i class="c-A"></i>Asistió</span><span><i class="c-T"></i>Tardanza</span><span><i class="c-F"></i>Falta</span><span><i class="c-V"></i>Ausencia justificada</span></div></div>
            <div class="col-md-6">
              <h3 class="h-sec">Comparado con su área (<?= e(area($c)) ?>)</h3>
              <div class="comparar"><span>Puntualidad</span><?= medidor($punt, 'g1') ?><span class="num"><?= round($punt) ?> %</span></div>
              <div class="comparar"><span class="sub2" style="display:inline">Promedio del área</span><?= medidor($promP, 'gr') ?><span class="num sub2" style="display:block"><?= round($promP) ?> %</span></div>
              <dl class="resumen mt-3 mb-3">
                <dt>Tardanzas</dt><dd><?= $ra['T'] ?><?= $ra['min'] ? ' <span class="sub2 d-inline fw-normal">('.$ra['min'].' min)</span>' : '' ?></dd>
                <dt>Faltas</dt><dd class="<?= $ra['F'] ? 'text-danger' : '' ?>"><?= $ra['F'] ?></dd>
                <dt>Días con ausencia justificada</dt><dd><?= $ra['V'] + $ra['D'] + $ra['L'] ?></dd>
                <dt>Horario</dt><dd><?= e($h['entrada'].' a '.$h['salida']) ?></dd>
              </dl>
              <div class="contexto"><?= ic('info') ?> Tardanzas: <?= $contexto($ra['T'] - $promT, 'días', 'día', 'días') ?> (<?= num($promT, 1) ?> por persona).</div>
            </div>
          </div>
        </div>
      </section>
    </div>
    <div class="col-xl-4">
      <section class="card mb-0 h-100" aria-labelledby="r-eq">
        <div class="card-header"><h2 id="r-eq">Su equipo</h2><span class="ctx"><?= pl(count($equipo) + 1, 'persona', 'personas') ?> en el área</span></div>
        <div class="card-body equipo">
          <?php $mini = fn($x, $sub = null) => '<a class="persona-mini" href="'.url('colaborador', ['id' => $x['id']]).'">'.avatar($x, 'm').'<span class="min-w-0"><b>'.e(nomc($x)).'</b><span class="sub2">'.e($sub ?? puesto($x)).'</span></span></a>'; ?>
          <div><h3>Jefe directo</h3><?= $jefe ? $mini($jefe) : '<p class="sub2 mb-0">No reporta a otra persona.</p>' ?></div>
          <div><h3>Lo reemplaza en ausencias</h3><?= $reemp ? $mini($reemp) : '<p class="sub2 mb-0">Sin reemplazo asignado.</p>' ?></div>
          <?php if ($aCargo): ?><div><h3>A su cargo · <?= count($aCargo) ?></h3><?php foreach (array_slice($aCargo, 0, 3) as $x) echo $mini($x); ?><?= count($aCargo) > 3 ? '<div class="mt-1">'.grupo_fotos(array_slice($aCargo, 3), 8).'</div>' : '' ?></div><?php endif; ?>
          <?php if ($companeros): ?><div><h3>Compañeros con el mismo jefe · <?= count($companeros) ?></h3><?= grupo_fotos($companeros, 8) ?></div><?php endif; ?>
        </div>
      </section>
    </div>
  </div>

  <div class="row g-4 mt-1">
    <?php if ($verSueldo && $serieS): $var = $sueldoIni ? ((float)$c['sueldo'] - $sueldoIni) / $sueldoIni * 100 : 0; ?>
    <div class="col-xl-7">
      <section class="card mb-0 h-100" aria-labelledby="r-su">
        <div class="card-header"><h2 id="r-su">Evolución del sueldo</h2><span class="ctx">Mes a mes desde <?= e($serieS[0]['et']) ?> · los puntos marcan cambios</span></div>
        <div class="card-body">
          <div class="d-flex gap-4 flex-wrap mb-2">
            <div><span class="sub2">Sueldo actual</span><span class="cifra-m"><?= soles($c['sueldo']) ?></span></div>
            <div><span class="sub2">Variación desde el ingreso</span><span class="cifra-m"><?= $cambiosS ? '+'.num($var, 1).' %' : 'Sin cambios' ?></span></div>
            <div><span class="sub2">Último cambio</span><span class="cifra-m"><?= $cambiosS ? e(hace(end($cambiosS)['fecha'])) : vacio_d() ?></span></div>
          </div>
          <?= linea($serieS, ['alto' => 170, 'escalon' => true, 'min' => (int)(floor(min(array_column($serieS, 'v')) * .8 / 500) * 500), 'cada' => max(1, (int)ceil(count($serieS) / 7)), 'ml' => 52, 'fmt' => fn($v) => 'S/ '.n_eje($v), 'titulo' => 'Evolución del sueldo mensual']) ?>
          <div class="contexto mt-2"><?= ic('info') ?> <?= count($mismos) > 1 ? 'Promedio del puesto ('.e(puesto($c)).', '.count($mismos).' personas): <b>'.soles(array_sum(array_column($mismos, 'sueldo')) / count($mismos)).'</b>' : 'Es la única persona en el puesto '.e(puesto($c)).'.' ?></div>
        </div>
      </section>
    </div>
    <?php endif; ?>
    <div class="<?= $verSueldo && $serieS ? 'col-xl-5' : 'col-xl-6' ?>">
      <section class="card mb-0 h-100" aria-labelledby="r-va">
        <div class="card-header"><h2 id="r-va">Vacaciones</h2><a href="#" class="small" data-ir-tab="vacaciones">Ver detalle</a></div>
        <div class="card-body">
          <?= barra_saldo($s) ?>
          <div class="contexto mt-3"><?= ic('info') ?> El promedio del área es de <b><?= num($promS, 1) ?> días</b> disponibles por persona.</div>
          <?php if ($prox): ?><h3 class="h-sec mt-3">Próximas</h3><ul class="lista lista-compacta">
            <?php foreach (array_slice($prox, 0, 3) as $v): $r = por_id('colaboradores', (int)($v['reemplazoId'] ?? 0)); ?>
            <li><span class="dia-num"><b><?= (int)substr($v['desde'], 8) ?></b><small><?= MES3[(int)substr($v['desde'], 5, 2) - 1] ?></small></span><div class="min-w-0 flex-grow-1"><b class="d-block"><?= e($v['tipo']) ?> · <?= pl($v['dias'], 'día', 'días') ?></b><span class="sub2"><?= f($v['desde']) ?> al <?= f($v['hasta']) ?> · empieza <?= e(hace($v['desde'])) ?><?= $r ? ' · reemplazo: '.e(nomc($r)) : '' ?></span></div><?= estado($v['estado']) ?></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </div>
      </section>
    </div>
    <?php if (!($verSueldo && $serieS)): ?><div class="col-xl-6"><?= $fechasHtml ?></div><?php endif; ?>
  </div>
  <?php if ($verSueldo && $serieS): ?><div class="row g-4 mt-1"><div class="col-xl-6"><?= $fechasHtml ?></div>
    <div class="col-xl-6">
      <section class="card mb-0 h-100" aria-labelledby="r-mo">
        <div class="card-header"><h2 id="r-mo">Últimos movimientos</h2><a href="#" class="small" data-ir-tab="historial">Historial completo</a></div>
        <ul class="tl tl-comp">
          <?php foreach (array_slice($hist, 0, 4) as $x): $t = TL[$x['tipo']] ?? TL['_']; ?>
          <li><span class="ic <?= $t[1] ?>"><?= ic($t[0]) ?></span><div><b><?= e($x['tipo']) ?></b><span class="sub2"><?= cuando($x['fecha']) ?><?= $x['detalle'] ? ' · '.e($x['detalle']) : '' ?></span></div></li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div></div><?php endif; ?>
</div>

<!-- ============ Datos personales ============ -->
<div <?= $panel('personales') ?>>
  <section class="card"><div class="card-header"><h2>Datos personales</h2><?= $editar ? '<a class="btn btn-sec btn-sm" href="'.url('colaborador_form', ['id' => $c['id']]).'">'.ic('pencil-simple').' Editar</a>' : '' ?></div>
    <div class="card-body"><div class="row">
      <?= dato('Apellidos y nombres', e(nom($c))) ?><?= dato('Documento', e($c['tipoDoc'].' '.$c['nroDoc'])) ?>
      <?= dato('Fecha de nacimiento', f($c['fecNac']).' <span class="sub2 d-inline fw-normal">· '.$edad.' años · cumpleaños '.e(hace(cumple_proximo($c['fecNac']))).'</span>') ?>
      <?= dato('Sexo', $c['sexo'] === 'F' ? 'Femenino' : 'Masculino') ?><?= dato('Estado civil', e($c['estadoCivil'])) ?>
      <?= dato('Celular', $c['telefono'] ? e($c['telefono']) : '<span class="text-warning-emphasis">'.ic('warning').' Falta registrar</span>') ?>
      <?= dato('Correo', $c['email'] ? '<a href="mailto:'.e($c['email']).'">'.e($c['email']).'</a>' : '') ?>
      <?= dato('Dirección', $c['direccion'] ? e($c['direccion'].', '.$c['distrito']) : '<span class="text-warning-emphasis">'.ic('warning').' Falta registrar</span>') ?>
    </div></div>
  </section>
</div>

<!-- ============ Datos laborales ============ -->
<div <?= $panel('laboral') ?>>
  <section class="card"><div class="card-header"><h2>Datos laborales</h2><?= $editar && $c['estado'] === 'Activo' ? btn_panel('movimiento', ['id' => $c['id']], ic('arrows-left-right').' Registrar movimiento') : '' ?></div>
    <div class="card-body"><div class="row">
      <?php $emp = por_id('empresas', $c['empresaId']); $sd = por_id('sedes', $c['sedeId']); $cc = por_id('ccs', (int)$c['ccId']); ?>
      <?= dato('Empresa', '<span class="d-inline-flex gap-2 align-items-center">'.logo_empresa($emp, 'sm').e($emp['razon']).'</span>') ?>
      <?= dato('Sede', '<span class="d-inline-flex gap-2 align-items-center">'.foto_sede($sd, 'mini').'<span>'.e($sd['nombre']).'<span class="sub2">'.e($sd['direccion']).'</span></span></span>') ?>
      <?= dato('Área', e(area($c))) ?><?= dato('Puesto', e(puesto($c))) ?>
      <?= dato('Jefe directo', $jefe ? '<a href="'.url('colaborador', ['id' => $jefe['id']]).'">'.e(nom($jefe)).'</a>' : '') ?>
      <?= dato('Horario', e($h['nombre']).' <span class="sub2 d-inline fw-normal">· '.e($h['entrada'].' a '.$h['salida']).' · tolerancia '.$h['tolerancia'].' min</span>') ?>
      <?= dato('Centro de costo', $cc ? e($cc['codigo'].' · '.$cc['nombre']) : '<span class="text-warning-emphasis">'.ic('warning').' Falta asignar</span>') ?>
      <?= dato('Código', e($c['codigo'])) ?>
      <?= dato('Fecha de ingreso', f($c['fecIngreso']).' <span class="sub2 d-inline fw-normal">· '.e(antiguedad($c['fecIngreso'], $c['fecCese'] ?: null)).'</span>') ?>
      <?= $verSueldo ? dato('Sueldo mensual', soles($c['sueldo'])) : '' ?>
      <?= $c['estado'] === 'Cesado' ? dato('Cese', f($c['fecCese']).' · '.e($c['motivoCese'])) : '' ?>
    </div></div>
  </section>
</div>

<!-- ============ Contrato y documentos ============ -->
<div <?= $panel('contrato') ?>>
  <div class="row g-4">
    <div class="col-lg-6">
      <section class="card mb-0 h-100"><div class="card-header"><h2>Contrato vigente</h2><?= $editar && $c['estado'] === 'Activo' && $c['tipoContrato'] !== 'Indeterminado' ? btn_panel('renovar', ['id' => $c['id']], ic('arrows-clockwise').' Renovar') : '' ?></div>
        <div class="card-body">
          <?php $renov = array_values(array_filter($hist, fn($x) => $x['tipo'] === 'Renovación')); $iniC = $renov ? fecha_iso($renov[0]['antes']) ?? $c['fecIngreso'] : $c['fecIngreso']; ?>
          <dl class="resumen mb-3"><dt>Tipo</dt><dd><?= e($c['tipoContrato']) ?></dd><dt>Inicio del periodo</dt><dd><?= f($iniC) ?></dd>
            <dt>Término</dt><dd><?= $c['finContrato'] ? f($c['finContrato']).' ('.e(hace($c['finContrato'])).')' : 'Sin fecha de término' ?></dd><dt>Renovaciones</dt><dd><?= count($renov) ?></dd></dl>
          <?php if ($c['finContrato'] && $c['estado'] === 'Activo'): $tot = max(1, dif_dias($iniC, $c['finContrato'])); $va = min($tot, max(0, dif_dias($iniC, HOY))); ?>
            <div class="d-flex justify-content-between sub2 mb-1"><span>Transcurrido</span><span><?= $c['finContrato'] < HOY ? 'Vencido' : pl(dif_dias(HOY, $c['finContrato']), 'día restante', 'días restantes') ?></span></div>
            <?= medidor($va / $tot * 100, $c['finContrato'] < add_dias(HOY, (int)param('avisoContratoDias')) ? 'wa' : 'g1') ?>
          <?php endif; ?>
        </div></section>
    </div>
    <div class="col-lg-6">
      <?php
      $docsReales = array_values(array_filter(tabla('documentos'), fn($d) => (int)$d['colabId'] === (int)$c['id']));
      usort($docsReales, fn($a, $b) => strcmp($b['fecha'], $a['fecha']) ?: $b['id'] - $a['id']);
      ?>
      <section class="card mb-0 h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <h2>Documentos</h2>
            <span class="ctx"><?= pl(count($docsReales), 'documento registrado', 'documentos registrados') ?></span>
          </div>
          <?= $editar ? btn_panel('subir_documento', ['id' => $c['id']], ic('upload-simple').' Subir documento', 'btn btn-primary btn-sm') : '' ?>
        </div>
        <?php if ($docsReales): ?>
          <ul class="lista">
            <?php foreach ($docsReales as $d):
              $ext = strtolower(pathinfo($d['archivo'], PATHINFO_EXTENSION));
              $icDoc = $ext === 'pdf' ? 'file-pdf' : 'file-image';
            ?>
            <li>
              <span class="doc-ic"><?= ic($icDoc) ?></span>
              <div class="flex-grow-1 min-w-0">
                <b class="d-block"><?= e($d['tipo']) ?> · <?= e($d['nombre_original']) ?></b>
                <span class="sub2"><?= strtoupper($ext) ?> · <?= num($d['tamano'] / 1024, 0) ?> KB · <?= cuando(substr($d['fecha'], 0, 10), false) ?> por <?= e($d['usuario']) ?></span>
              </div>
              <a href="index.php?descargar=<?= $d['id'] ?>" class="btn btn-ter btn-sm" target="_blank"><?= ic('download-simple') ?> Ver</a>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <?= estado_vacio('Aún no hay documentos registrados', 'Suba contratos, adendas o documentos de identidad.', $editar ? btn_panel('subir_documento', ['id' => $c['id']], 'Subir documento', 'btn btn-primary btn-sm') : '') ?>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>

<!-- ============ Historial ============ -->
<div <?= $panel('historial') ?>>
  <section class="card"><div class="card-header"><h2>Historial</h2><span class="ctx"><?= pl(count($hist), 'evento', 'eventos') ?> desde el ingreso</span></div>
    <div class="card-body">
      <?php if ($hist): ?><ul class="tl">
        <?php foreach ($hist as $x): $t = TL[$x['tipo']] ?? TL['_']; ?>
        <li><span class="ic <?= $t[1] ?>"><?= ic($t[0]) ?></span>
          <div class="t"><?= e($x['tipo']) ?></div>
          <div class="f"><?= cuando($x['fecha']) ?> · <?= f($x['fecha']) ?> · registrado por <?= e($x['usuario']) ?></div>
          <?php if ($x['detalle']): ?><div><?= e($x['detalle']) ?></div><?php endif; ?>
          <?php if ($x['antes'] !== '' || $x['despues'] !== ''): ?><div class="cambio"><s class="text-secondary"><?= e($x['antes']) ?></s><?= ic('arrow-right') ?><b><?= e($x['despues']) ?></b></div><?php endif; ?>
        </li>
        <?php endforeach; ?></ul>
      <?php else: ?><?= estado_vacio('Sin movimientos registrados') ?><?php endif; ?>
    </div></section>
</div>

<!-- ============ Asistencia ============ -->
<div <?= $panel('asistencia') ?>>
  <?php $base = $propia ? [] : ['id' => $c['id']]; $pg = $propia ? $p : 'colaborador';
  $mAnt = substr(add_meses("$ym-15", -1), 0, 7); $mSig = substr(add_meses("$ym-15", 1), 0, 7);
  $marcas = []; for ($x = $fin; $x >= $ini; $x = add_dias($x, -1)) { $k = codigo_dia($c, $x); if ($k === 'X') continue; $marcas[] = [$x, $k, marca($c['id'], $x)]; } ?>
  <section class="card">
    <div class="card-header"><h2>Marcaciones</h2>
      <div class="mes-nav"><a class="btn btn-sec btn-sm btn-ico" href="<?= url($pg, $base + ['tab' => 'asistencia', 'mes' => $mAnt]) ?>" aria-label="Mes anterior"><?= ic('caret-left') ?></a><b><?= mes_nombre((int)substr($ym, 5)).' '.substr($ym, 0, 4) ?></b>
        <?php if ($ym < substr(HOY, 0, 7)): ?><a class="btn btn-sec btn-sm btn-ico" href="<?= url($pg, $base + ['tab' => 'asistencia', 'mes' => $mSig]) ?>" aria-label="Mes siguiente"><?= ic('caret-right') ?></a><?php else: ?><span class="btn btn-sec btn-sm btn-ico disabled" aria-hidden="true"><?= ic('caret-right') ?></span><?php endif; ?></div></div>
    <div class="card-body border-bottom">
      <div class="row g-4">
        <div class="col-md-4"><?= minicalendario($c, $ym) ?></div>
        <div class="col-md-8"><div class="cifras cifras-sin-borde">
          <div><div class="l">Asistencia</div><div class="v"><?= $ra['prog'] ? round($punt).' <small>%</small>' : vacio_d() ?></div><div class="ctx"><?= $ra['A'] ?> de <?= $ra['prog'] ?> días a tiempo</div></div>
          <div><div class="l">Tardanzas</div><div class="v"><?= $ra['T'] ?></div><div class="ctx"><?= $ra['min'] ?> minutos en total</div></div>
          <div><div class="l">Faltas</div><div class="v"><?= $ra['F'] ?></div><div class="ctx"><?= $ra['F'] ? 'Sin justificar' : 'Ninguna' ?></div></div>
          <div><div class="l">Ausencias justificadas</div><div class="v"><?= $ra['V'] + $ra['D'] + $ra['L'] ?></div><div class="ctx">Vacaciones, descansos y permisos</div></div>
        </div></div>
      </div>
    </div>
    <?php if ($marcas): ?>
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Fecha</th><th>Entrada</th><th>Salida</th><th class="num">Horas</th><th>Estado</th><th>Origen</th><?= puede('asistencia.editar') ? '<th></th>' : '' ?></tr></thead>
      <tbody>
      <?php foreach ($marcas as [$x, $k, $m]): if ($k === 'N') continue; $em = $m ? estado_marca($m) : null; ?>
        <tr class="<?= in_array($k, ['F', 'S'], true) ? 'fila-alerta' : '' ?>">
          <td><b><?= f($x) ?></b><span class="sub2"><?= e(mb_convert_case(DIAS[dia_sem($x)], MB_CASE_TITLE)) ?></span></td>
          <td><?= $m ? e($m['entrada']) : vacio_d() ?></td><td><?= $m && $m['salida'] ? e($m['salida']) : ($m ? '<span class="text-warning-emphasis">Sin salida</span>' : vacio_d()) ?></td>
          <td class="num"><?= $em && $em['min'] ? duracion($em['min']) : vacio_d() ?></td>
          <td><?= ['A' => estado('Activo', 'A tiempo'), 'T' => '<span class="st wa">Tardanza</span> <span class="sub2 d-inline">'.($em['tard'] ?? 0).' min</span>', 'F' => '<span class="st er">Falta</span>', 'S' => '<span class="st er">Sin marcar</span>', 'V' => '<span class="st in">Vacaciones</span>', 'D' => '<span class="st in">Descanso médico</span>', 'L' => '<span class="st in">Permiso</span>'][$k] ?? '' ?></td>
          <td><?= $m ? e($m['origen']).($m['obs'] ? '<span class="sub2" data-tip="'.e($m['obs']).'">'.e(mb_strimwidth($m['obs'], 0, 28, '…')).'</span>' : '') : '' ?></td>
          <?php if (puede('asistencia.editar')): ?><td class="text-end"><?= in_array($k, ['A', 'T', 'F', 'S'], true) ? btn_panel('marcar', ['id' => $c['id'], 'fecha' => f($x)], $m ? 'Corregir' : 'Registrar', 'btn btn-ter btn-sm') : '' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><?= estado_vacio('Sin marcaciones en este mes') ?><?php endif; ?>
  </section>
</div>

<!-- ============ Vacaciones ============ -->
<div <?= $panel('vacaciones') ?>>
  <div class="row g-4">
    <div class="col-lg-5">
      <section class="card mb-0"><div class="card-header"><h2>Saldo</h2><?= ($esYo || $editar) && $c['estado'] === 'Activo' ? btn_panel('solicitud', ['id' => $c['id']], ic('plus').' Nueva solicitud') : '' ?></div>
        <div class="card-body"><?= barra_saldo($s) ?></div>
        <?php if ($s['per']): ?><div class="table-responsive"><table class="table mb-0 tabla-mini"><thead><tr><th>Periodo</th><th class="num">Ganados</th><th class="num">Usados</th><th>Vence</th></tr></thead><tbody>
          <?php foreach (array_reverse($s['per']) as $pe): ?><tr><td><?= f($pe['ini']) ?> – <?= f($pe['fin']) ?></td><td class="num"><?= $pe['dias'] ?></td><td class="num"><?= $pe['usados'] ?></td>
            <td><?= $pe['usados'] >= $pe['dias'] ? '<span class="sub2 d-inline">Gozado</span>' : ($pe['vence'] < HOY ? '<span class="text-danger fw-bold">Venció '.e(hace($pe['vence'])).'</span>' : f($pe['vence']).' <span class="sub2 d-inline">('.e(hace($pe['vence'])).')</span>') ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <div class="card-footer sub2">Días por año: <?= param('diasVacAnio') ?> (D. L. 713) · <span class="text-warning-emphasis">pendiente de validación legal</span></div>
      </section>
    </div>
    <div class="col-lg-7">
      <section class="card mb-0"><div class="card-header"><h2>Solicitudes y ausencias</h2><span class="ctx"><?= pl(count($vacs), 'registro', 'registros') ?></span></div>
        <?php if ($vacs): ?><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Tipo</th><th>Fechas</th><th class="num">Días</th><th>Estado</th><th></th></tr></thead><tbody>
          <?php foreach ($vacs as $v): $r = por_id('colaboradores', (int)($v['reemplazoId'] ?? 0)); ?>
          <tr><td><?= e($v['tipo']) ?><span class="sub2">Solicitada <?= e(hace($v['fecSol'])) ?></span></td>
            <td><?= f($v['desde']) ?> al <?= f($v['hasta']) ?><span class="sub2"><?= $r ? 'Reemplazo: '.e(nomc($r)) : '' ?></span></td><td class="num"><?= $v['dias'] ?></td>
            <td><?= estado($v['estado']) ?><span class="sub2"><?= $v['resuelto'] ? 'por '.e($v['resuelto']) : '' ?><?= $v['estado'] === 'Rechazada' && $v['obs'] ? ': '.e($v['obs']) : '' ?></span></td>
            <td class="text-end"><?php if ($v['estado'] === 'Pendiente'): ?><?= puede('vacaciones.aprobar') && !$esYo ? btn_panel('revisar', ['id' => $v['id']], 'Revisar', 'btn btn-sec btn-sm') : '' ?><?= $esYo || $editar ? btn_panel('confirmar', ['que' => 'anular', 'id' => $v['id']], 'Anular', 'btn btn-ter btn-sm') : '' ?><?php endif; ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
        <?php else: ?><?= estado_vacio('Sin solicitudes registradas', $esYo ? 'Cuando solicite vacaciones o un permiso, aparecerá aquí.' : '', $esYo ? btn_panel('solicitud', ['id' => $c['id']], 'Solicitar vacaciones', 'btn btn-primary btn-sm') : '') ?><?php endif; ?>
      </section>
    </div>
  </div>
</div>

<!-- ============ Pensión y banco ============ -->
<div <?= $panel('pension') ?>>
  <?php
    $ver    = $esYo || !empty($_SESSION['ver_sensible'][$c['id']]);
    // Descifrar solo si el usuario puede ver los datos sensibles
    $cuspp_plain  = ($ver && puede('personal.sensible')) ? descifrar($c['cuspp'])  : $c['cuspp'];
    $cuenta_plain = ($ver && puede('personal.sensible')) ? descifrar($c['cuenta']) : $c['cuenta'];
    $oculto = fn($t) => $t ? '•••• ' . e(substr($t, -4)) : '';
  ?>
  <section class="card"><div class="card-header"><h2>Pensión y cuenta de sueldo</h2>
    <?php if (!$ver && puede('personal.sensible')): ?><form method="post" action="index.php?accion=ver_sensible" class="d-flex align-items-center gap-2"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><span class="sub2 d-none d-md-inline">La consulta queda registrada en la auditoría</span><button class="btn btn-sec btn-sm"><?= ic('eye') ?> Mostrar datos</button></form><?php endif; ?></div>
    <div class="card-body"><div class="row">
      <?= dato('Sistema de pensiones', e($c['pension'])) ?>
      <?= dato('CUSPP', $c['pension'] === 'ONP' ? '<span class="sub2 d-inline">No aplica (ONP)</span>' : ($ver ? e($cuspp_plain) : $oculto($cuspp_plain))) ?>
      <?= dato('Banco', e($c['banco'])) ?>
      <?= dato('Cuenta de sueldo', $ver ? e($cuenta_plain) : $oculto($cuenta_plain)) ?>
    </div>
    <?php if (!$ver && !puede('personal.sensible')): ?><p class="sub2 mb-0"><?= ic('lock-simple') ?> Su perfil no permite ver estos datos completos.</p><?php endif; ?>
    </div></section>
</div>
