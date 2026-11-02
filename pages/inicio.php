<?php
/**
 * inicio.php — Pantalla de inicio. Para RR. HH. y jefaturas: asuntos por resolver, asistencia de hoy,
 * cifras con contexto, tardanzas de los últimos días, evolución de la plantilla y novedades.
 * Para el perfil Colaborador: su día, su asistencia del mes, sus vacaciones y su contrato.
 */
if (!puede('personal.ver')) { include __DIR__.'/inicio_colaborador.php'; return; }

$emp = por_id('empresas', empresa_activa());
$pagina['titulo'] = 'Resumen del día';
$pagina['sub'] = e(fecha_larga(HOY)).' · '.($emp ? e($emp['razon']) : 'Todas las empresas');

$todos = colabs_visibles(); $act = activos(); $ids = array_column($todos, 'id');
/* Asistencia de hoy */
$prog = array_values(array_filter($act, fn($c) => laborable($c, HOY) || ausencia($c, HOY)));
$cod = []; foreach ($prog as $c) $cod[$c['id']] = codigo_dia($c, HOY);
$de = fn(array $k) => array_values(array_filter($prog, fn($c) => in_array($cod[$c['id']], $k, true)));
$presentes = $de(['A','T']); $tard = $de(['T']); $sin = $de(['S']); $aus = $de(['V','D','L']);
$esperan = count($prog) - count($aus);

/* Asuntos por resolver, ordenados por urgencia */
$pend = array_values(array_filter(tabla('vacaciones'), fn($v) => $v['estado'] === 'Pendiente' && in_array($v['colabId'], $ids, true)));
usort($pend, fn($a, $b) => strcmp($a['fecSol'], $b['fecSol']));
$venc15 = array_values(array_filter($act, fn($c) => $c['tipoContrato'] !== 'Indeterminado' && $c['finContrato'] && $c['finContrato'] >= HOY && $c['finContrato'] <= add_dias(HOY, 15)));
usort($venc15, fn($a, $b) => strcmp($a['finContrato'], $b['finContrato']));
$vencidos = array_values(array_filter($act, fn($c) => $c['tipoContrato'] !== 'Indeterminado' && $c['finContrato'] && $c['finContrato'] < HOY));
$incomp = array_values(array_filter($act, 'incompleta'));
$saldos = []; foreach ($act as $c) $saldos[$c['id']] = saldo_vac($c);
$acum = array_values(array_filter($act, fn($c) => $saldos[$c['id']]['saldo'] >= 60));
$perv = array_values(array_filter($act, fn($c) => $saldos[$c['id']]['viejo'] && $saldos[$c['id']]['viejo']['vence'] < add_dias(HOY, 90)));
$nombres = fn(array $l) => e(implode(', ', array_map('nomc', array_slice($l, 0, 3)))).(count($l) > 3 ? ' y '.(count($l) - 3).' más' : '');
$porSede = function (array $l) { $g = []; foreach ($l as $c) $g[sede($c)] = ($g[sede($c)] ?? 0) + 1; return e(implode(', ', array_map(fn($s, $n) => "$n en $s", array_keys($g), $g))); };
$items = array_filter([
    $vencidos ? ['er', count($vencidos), pl(count($vencidos), 'contrato vencido sin renovar ni cese', 'contratos vencidos sin renovar ni cese'), $nombres($vencidos), url('contratos'), 'Revisar contratos', grupo_fotos($vencidos, 3)] : null,
    $sin ? ['er', count($sin), pl(count($sin), 'persona sin marcar entrada hoy', 'personas sin marcar entrada hoy'), $porSede($sin), url('asistencia'), 'Ver asistencia', grupo_fotos($sin, 3)] : null,
    $pend ? ['wa', count($pend), pl(count($pend), 'solicitud por aprobar', 'solicitudes por aprobar'), 'La más antigua espera '.hace($pend[0]['fecSol']).' ('.e(nomc(por_id('colaboradores', $pend[0]['colabId']))).')', url('aprobaciones'), 'Revisar', grupo_fotos(array_map(fn($v) => por_id('colaboradores', $v['colabId']), $pend), 3)] : null,
    $venc15 ? ['wa', count($venc15), pl(count($venc15), 'contrato vence en los próximos 15 días', 'contratos vencen en los próximos 15 días'), e(implode(', ', array_map(fn($c) => nomc($c).' ('.hace($c['finContrato']).')', array_slice($venc15, 0, 3)))).(count($venc15) > 3 ? ' y '.(count($venc15) - 3).' más' : ''), url('contratos'), 'Ver contratos', grupo_fotos($venc15, 3)] : null,
    $tard ? ['wa', count($tard), pl(count($tard), 'tardanza hoy', 'tardanzas hoy'), $nombres($tard), url('asistencia'), 'Ver asistencia', grupo_fotos($tard, 3)] : null,
    $perv ? ['', count($perv), pl(count($perv), 'periodo vacacional vence en menos de 90 días', 'periodos vacacionales vencen en menos de 90 días'), $nombres($perv), url('vacaciones_saldos'), 'Ver saldos', ''] : null,
    $acum ? ['', count($acum), pl(count($acum), 'persona con 60 días o más de vacaciones acumuladas', 'personas con 60 días o más de vacaciones acumuladas'), $nombres($acum), url('vacaciones_saldos'), 'Ver saldos', ''] : null,
    $incomp ? ['', count($incomp), pl(count($incomp), 'ficha con datos incompletos', 'fichas con datos incompletos'), 'Falta dirección, teléfono o centro de costo', url('personal', ['vista' => 'incompletos']), 'Completar', ''] : null,
]);

/* Cifras con contexto */
$finMesAnt = add_dias(substr(HOY, 0, 7).'-01', -1);
$plantAnt = plantilla_al($finMesAnt, $todos); $dif = count($act) - $plantAnt;
$altasMes = count(array_filter($todos, fn($c) => substr($c['fecIngreso'], 0, 7) === substr(HOY, 0, 7)));
$bajas12 = count(array_filter($todos, fn($c) => $c['fecCese'] && $c['fecCese'] > add_meses(HOY, -12)));
$prom12 = (plantilla_al(add_meses(HOY, -12), $todos) + count($act)) / 2;
$totalSaldo = array_sum(array_map(fn($s) => max(0, $s['saldo']), $saldos));

/* Tardanzas de los últimos 20 días hábiles (para el gráfico y el promedio) */
$dias = []; for ($x = HOY; count($dias) < 20 && $x > add_dias(HOY, -40); $x = add_dias($x, -1)) if (dia_sem($x) !== 0) array_unshift($dias, $x);
$serie = []; $sumaPrev = 0; $nPrev = 0;
foreach ($dias as $x) {
    $t = $fa = $pr = $pg = 0;
    foreach ($act as $c) { if (!laborable($c, $x)) continue; $k = codigo_dia($c, $x); if (in_array($k, ['V','D','L'], true)) continue; $pg++; if ($k === 'T') $t++; if ($k === 'F') $fa++; if ($k === 'A' || $k === 'T') $pr++; }
    if ($x !== HOY) { $sumaPrev += $t; $nPrev++; }
    $serie[] = ['et' => DIAS1[dia_sem($x)].' '.(int)substr($x, 8), 'v' => $t, 'tip' => mb_convert_case(DIAS[dia_sem($x)], MB_CASE_TITLE).' '.f($x).': '.pl($t, 'tardanza', 'tardanzas').' · '.pl($fa, 'falta', 'faltas').' · asistencia '.pct($pr, $pg), 'clase' => $x === HOY ? '' : ''];
}
$promT = $nPrev ? $sumaPrev / $nPrev : 0;
$difT = count($tard) - $promT;

/* Plantilla de los últimos 12 meses */
$plant = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = substr(add_meses(substr(HOY, 0, 7).'-15', -$i), 0, 7); $corte = min(fin_mes($ym), HOY);
    $al = count(array_filter($todos, fn($c) => substr($c['fecIngreso'], 0, 7) === $ym)); $ba = count(array_filter($todos, fn($c) => $c['fecCese'] && substr($c['fecCese'], 0, 7) === $ym));
    $plant[] = ['et' => MES3[(int)substr($ym, 5) - 1], 'v' => plantilla_al($corte, $todos), 'tip' => mes_nombre((int)substr($ym, 5)).' '.substr($ym, 0, 4).': '.plantilla_al($corte, $todos).' activos · '.pl($al, 'alta', 'altas').' · '.pl($ba, 'baja', 'bajas'), 'marca' => $ba > 0];
}

/* Novedades */
$semana = array_map(fn($i) => add_dias(HOY, $i), range(0, 6)); $md = fn($x) => substr($x, 5);
$eventos = [];
foreach ($act as $c) { foreach ($semana as $x) {
    if ($md($x) === $md($c['fecNac'])) $eventos[] = [$c, 'Cumpleaños', $x, 'cake'];
    if ($md($x) === $md($c['fecIngreso']) && substr($c['fecIngreso'], 0, 4) < substr(HOY, 0, 4)) $eventos[] = [$c, pl((int)substr(HOY, 0, 4) - (int)substr($c['fecIngreso'], 0, 4), 'año', 'años').' en la empresa', $x, 'medal'];
} }
usort($eventos, fn($a, $b) => strcmp($a[2], $b[2]));
$movs = array_values(array_filter(tabla('historial'), fn($h) => in_array($h['colabId'], $ids, true) && $h['tipo'] !== 'Ingreso' && $h['fecha'] <= HOY));
usort($movs, fn($a, $b) => strcmp($b['fecha'], $a['fecha']) ?: $b['id'] - $a['id']);
$sedes = array_filter(tabla('sedes'), fn($s) => $s['activo'] && (!$emp || $s['empresaId'] === $emp['id']));

$cant_emp = count(tabla('empresas'));
$cant_sed = count(tabla('sedes'));
$cant_are = count(tabla('areas'));
$cant_pue = count(tabla('puestos'));
$cant_hor = count(tabla('horarios'));
$cant_col = count(tabla('colaboradores'));
$cant_usr = count(tabla('usuarios'));

$paso1 = ($cant_emp > 0 && $cant_sed > 0);
$paso2 = ($cant_are > 0 && $cant_pue > 0 && $cant_hor > 0);
$paso3 = ($cant_col > 0);
$paso4 = ($cant_usr > 1);
?>

<?php if ($cant_emp === 0): ?>
<section class="card destacada mb-4">
  <div class="card-header">
    <h2>Guía de primeros pasos</h2>
    <span class="ctx">Configure su organización para comenzar a utilizar AyniHR</span>
  </div>
  <div class="card-body">
    <p class="text-secondary mb-3">Bienvenido a AyniHR. Complete los siguientes pasos para poner en marcha el sistema:</p>
    <div class="row g-3">
      <div class="col-md-3">
        <div class="p-3 border rounded h-100 bg-white">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="badge <?= $paso1 ? 'bg-success' : 'bg-primary' ?>"><?= $paso1 ? '1. Listo' : 'Paso 1' ?></span>
            <i class="ph <?= $paso1 ? 'ph-check-circle text-success' : 'ph-buildings text-primary' ?>" style="font-size:1.5rem"></i>
          </div>
          <h3 class="h6 fw-bold mb-1">Empresa y sedes</h3>
          <p class="small text-secondary mb-3">Registre la razón social, RUC y sus sedes operativas.</p>
          <a href="<?= url('organizacion', ['tabla' => 'empresas']) ?>" class="btn btn-sm <?= $paso1 ? 'btn-ter' : 'btn-primary' ?> w-100"><?= $paso1 ? 'Ver empresas' : 'Registrar empresa' ?></a>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 border rounded h-100 bg-white">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="badge <?= $paso2 ? 'bg-success' : ($paso1 ? 'bg-primary' : 'bg-secondary') ?>"><?= $paso2 ? '2. Listo' : 'Paso 2' ?></span>
            <i class="ph <?= $paso2 ? 'ph-check-circle text-success' : 'ph-tree-structure text-secondary' ?>" style="font-size:1.5rem"></i>
          </div>
          <h3 class="h6 fw-bold mb-1">Áreas, puestos y horarios</h3>
          <p class="small text-secondary mb-3">Defina la estructura orgánica y los turnos de trabajo.</p>
          <a href="<?= url('organizacion', ['tabla' => 'areas']) ?>" class="btn btn-sm <?= $paso2 ? 'btn-ter' : 'btn-sec' ?> w-100">Configurar estructura</a>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 border rounded h-100 bg-white">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="badge <?= $paso3 ? 'bg-success' : ($paso2 ? 'bg-primary' : 'bg-secondary') ?>"><?= $paso3 ? '3. Listo' : 'Paso 3' ?></span>
            <i class="ph <?= $paso3 ? 'ph-check-circle text-success' : 'ph-users text-secondary' ?>" style="font-size:1.5rem"></i>
          </div>
          <h3 class="h6 fw-bold mb-1">Colaboradores</h3>
          <p class="small text-secondary mb-3">Registre fichas o importe el personal desde un archivo CSV.</p>
          <a href="<?= url('colaborador_form') ?>" class="btn btn-sm <?= $paso3 ? 'btn-ter' : 'btn-sec' ?> w-100">Registrar personal</a>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 border rounded h-100 bg-white">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="badge <?= $paso4 ? 'bg-success' : ($paso3 ? 'bg-primary' : 'bg-secondary') ?>"><?= $paso4 ? '4. Listo' : 'Paso 4' ?></span>
            <i class="ph <?= $paso4 ? 'ph-check-circle text-success' : 'ph-user-gear text-secondary' ?>" style="font-size:1.5rem"></i>
          </div>
          <h3 class="h6 fw-bold mb-1">Crear usuarios</h3>
          <p class="small text-secondary mb-3">Asigne accesos a los colaboradores y jefaturas.</p>
          <a href="<?= url('usuarios') ?>" class="btn btn-sm <?= $paso4 ? 'btn-ter' : 'btn-sec' ?> w-100">Gestionar usuarios</a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">
    <section class="card destacada mb-0 h-100" aria-labelledby="t-res">
      <div class="card-header"><h2 id="t-res">Necesita tu atención</h2><span class="ctx"><?= $items ? pl(count($items), 'asunto', 'asuntos').' · ordenados por urgencia' : '' ?></span></div>
      <?php if ($items): ?>
      <ul class="bandeja">
        <?php foreach ($items as $x): ?>
        <li>
          <span class="n <?= $x[0] ?>"><?= $x[1] ?></span>
          <div class="min-w-0"><b><?= e($x[2]) ?></b><div class="det"><?= $x[3] ?></div></div>
          <span class="d-none d-md-inline-flex"><?= $x[6] ?></span>
          <a class="btn btn-sec btn-sm" href="<?= e($x[4]) ?>"><?= e($x[5]) ?></a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><?= estado_vacio('No hay asuntos pendientes', 'Todo está al día.') ?><?php endif; ?>
    </section>
  </div>
  <div class="col-xl-4">
    <section class="card mb-0 h-100" aria-labelledby="t-hoy">
      <div class="card-header"><h2 id="t-hoy">Asistencia de hoy</h2><a class="small" href="<?= url('asistencia') ?>">Detalle</a></div>
      <div class="card-body">
        <div class="d-flex align-items-end gap-2 mb-1"><span class="heroe" data-contar="<?= count($presentes) ?>"><?= count($presentes) ?></span><span class="heroe-de">de <?= $esperan ?> esperados</span></div>
        <p class="sub2 mb-3"><?= pct(count($presentes), $esperan) ?> presentes · <?= pl(count($tard), 'tardanza', 'tardanzas') ?> · <?= count($sin) ?> sin marcar · <?= pl(count($aus), 'ausencia justificada', 'ausencias justificadas') ?></p>
        <ul class="sedes-hoy">
          <?php foreach ($sedes as $s):
            $ps = array_filter($prog, fn($c) => $c['sedeId'] === $s['id'] && !in_array($cod[$c['id']], ['V','D','L'], true)); if (!$ps) continue;
            $pr = count(array_filter($ps, fn($c) => in_array($cod[$c['id']], ['A','T'], true))); $p100 = count($ps) ? $pr / count($ps) * 100 : 0; ?>
          <li data-tip="<?= e($s['nombre'].': '.$pr.' de '.count($ps).' presentes') ?>">
            <span class="sh-n"><?= e($s['nombre']) ?></span><?= medidor($p100, $p100 < 90 ? 'wa' : 'g1') ?><span class="sh-v"><?= $pr ?>/<?= count($ps) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  </div>
</div>

<section class="card mt-4" aria-label="Cifras del mes">
  <div class="cifras">
    <div><div class="l">Plantilla activa</div><div class="v" data-contar="<?= count($act) ?>"><?= count($act) ?></div>
      <div class="ctx"><b><?= ($dif >= 0 ? '+' : '').$dif ?></b> frente al <?= f($finMesAnt) ?> · <?= pl($altasMes, 'alta', 'altas') ?> este mes</div></div>
    <div><div class="l">Tardanzas hoy</div><div class="v"><?= count($tard) ?></div>
      <div class="ctx"><?php if (abs($difT) < .5): ?>En el promedio de los últimos días (<?= num($promT, 1) ?>)<?php else: ?><b class="<?= $difT > 0 ? 'sube' : 'baja' ?>"><?= num(abs($difT), 1) ?> <?= $difT > 0 ? 'más' : 'menos' ?></b> que el promedio diario (<?= num($promT, 1) ?>)<?php endif; ?></div></div>
    <div><div class="l">Rotación en 12 meses</div><div class="v"><?= $prom12 ? num($bajas12 / $prom12 * 100, 1) : '0' ?> <small>%</small></div>
      <div class="ctx"><?= pl($bajas12, 'baja', 'bajas') ?> sobre una plantilla promedio de <?= num($prom12) ?></div></div>
    <div><div class="l">Vacaciones por gozar</div><div class="v"><?= num($totalSaldo) ?> <small>días</small></div>
      <div class="ctx">Promedio de <b><?= num($act ? $totalSaldo / count($act) : 0, 1) ?></b> por persona · <?= count($acum) ?> con 60 o más</div></div>
  </div>
</section>

<div class="row g-4">
  <div class="col-xl-7">
    <section class="card mb-0 h-100" aria-labelledby="t-tard">
      <div class="card-header"><h2 id="t-tard">Tardanzas por día</h2><span class="ctx">Últimos 20 días hábiles</span></div>
      <div class="card-body"><?= columnas($serie, ['alto' => 200, 'ref' => $promT, 'ref_txt' => 'Promedio '.num($promT, 1), 'cada' => 2, 'titulo' => 'Tardanzas por día en los últimos 20 días hábiles']) ?></div>
    </section>
  </div>
  <div class="col-xl-5">
    <section class="card mb-0 h-100" aria-labelledby="t-plant">
      <div class="card-header"><h2 id="t-plant">Plantilla activa</h2><span class="ctx">Últimos 12 meses · los puntos marcan meses con bajas</span></div>
      <div class="card-body"><?= linea($plant, ['alto' => 200, 'min' => max(0, (int)(floor(min(array_column($plant, 'v')) / 10) * 10) - 10), 'etiqueta_final' => count($act).' activos', 'cada' => 2, 'titulo' => 'Plantilla activa en los últimos 12 meses']) ?></div>
    </section>
  </div>
</div>

<div class="row g-4 mt-1">
  <div class="col-lg-4">
    <section class="bloque-plano" aria-labelledby="t-aus">
      <div class="cab"><h2 id="t-aus">Ausentes hoy</h2><span class="sub2"><?= count($aus) ?></span></div>
      <?php if ($aus): ?><ul class="lista tarjeta-lista">
        <?php foreach ($aus as $c): $a = ausencia($c, HOY); $r = por_id('colaboradores', $a['reemplazoId'] ?? 0); ?>
        <li><?= avatar($c, 'm') ?><div class="min-w-0 flex-grow-1"><a class="nm" href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'vacaciones']) ?>"><?= e(nom($c)) ?></a>
          <span class="sub2"><?= e($a['tipo']) ?> hasta el <?= f($a['hasta']) ?> (<?= e(hace($a['hasta'])) ?>)<?= $r ? ' · lo reemplaza '.e(nomc($r)) : '' ?></span></div></li>
        <?php endforeach; ?></ul>
      <?php else: ?><p class="text-secondary">Nadie está ausente hoy.</p><?php endif; ?>
    </section>
  </div>
  <div class="col-lg-4">
    <section class="bloque-plano" aria-labelledby="t-sem">
      <div class="cab"><h2 id="t-sem">Esta semana</h2><span class="sub2">Cumpleaños y aniversarios</span></div>
      <?php if ($eventos): ?><ul class="lista tarjeta-lista">
        <?php foreach (array_slice($eventos, 0, 6) as [$c, $txt, $x, $icono]): ?>
        <li><?= avatar($c, 'm') ?><div class="min-w-0 flex-grow-1"><a class="nm" href="<?= url('colaborador', ['id' => $c['id']]) ?>"><?= e(nom($c)) ?></a><span class="sub2"><?= ic($icono) ?> <?= e($txt) ?></span></div>
          <span class="dia-ev <?= $x === HOY ? 'hoy' : '' ?>"><?= $x === HOY ? 'Hoy' : e(mb_substr(DIAS[dia_sem($x)], 0, 3)).' '.(int)substr($x, 8) ?></span></li>
        <?php endforeach; ?></ul>
      <?php else: ?><p class="text-secondary">No hay cumpleaños ni aniversarios esta semana.</p><?php endif; ?>
    </section>
  </div>
  <div class="col-lg-4">
    <section class="card mb-0" aria-labelledby="t-act">
      <div class="card-header"><h2 id="t-act">Actividad reciente</h2><a class="small" href="<?= url('auditoria') ?>"><?= puede('config.auditoria') ? 'Auditoría' : '' ?></a></div>
      <ul class="tl tl-comp">
        <?php foreach (array_slice($movs, 0, 6) as $h): $c = por_id('colaboradores', $h['colabId']); $t = TL[$h['tipo']] ?? TL['_']; ?>
        <li><span class="ic <?= $t[1] ?>"><?= ic($t[0]) ?></span><div><b><?= e($h['tipo']) ?></b> · <a href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'historial']) ?>"><?= e(nomc($c)) ?></a>
          <span class="sub2"><?= cuando($h['fecha']) ?> · <?= e($h['usuario']) ?></span></div></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</div>
