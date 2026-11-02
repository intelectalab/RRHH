<?php
/**
 * personal.php — Lista de colaboradores: distribución del equipo, vistas (activos, por vencer,
 * incompletos, cesados), filtros con recarga parcial, orden por columna, modo tabla o tarjetas,
 * selección masiva y menú de acciones por fila (botón ⋮ o clic derecho).
 */
$q = $_GET; $vista = $q['vista'] ?? 'activos'; $vistas = vistas_personal(); if (!isset($vistas[$vista])) $vista = 'activos';
$modo = ($q['modo'] ?? '') === 'tarjetas' ? 'tarjetas' : 'tabla';
$lista = filtrar_personal($q);
$todos = colabs_visibles(); $act = activos();
$editar = puede('personal.editar');

/* Parámetros actuales (para enlaces de orden, página y vista) */
$base = array_filter(['vista' => $vista, 'q' => $q['q'] ?? '', 'area' => $q['area'] ?? '', 'sede' => $q['sede'] ?? '', 'contrato' => $q['contrato'] ?? '', 'modo' => $modo === 'tarjetas' ? 'tarjetas' : '', 'ord' => $q['ord'] ?? '', 'dir' => $q['dir'] ?? ''], fn($v) => $v !== '');
$enlace = fn(array $cambios) => url('personal', array_merge($base, $cambios));

/* Paginación */
$por = (int)param('filasPagina'); $total = count($lista); $paginas = max(1, (int)ceil($total / $por));
$pag = max(1, min($paginas, (int)($q['pag'] ?? 1))); $visibles = array_slice($lista, ($pag - 1) * $por, $por);

/* Distribución del equipo activo */
$areas = array_filter(tabla('areas'), fn($a) => $a['activo']);
$dist = [];
foreach ($areas as $a) { $n = count(array_filter($act, fn($c) => $c['areaId'] === $a['id'])); if ($n) $dist[] = ['et' => $a['nombre'], 'v' => $n, 'url' => $enlace(['area' => $a['id'], 'vista' => 'activos', 'pag' => '']), 'tip' => 'Ver a las '.$n.' personas de '.$a['nombre']]; }
usort($dist, fn($a, $b) => $b['v'] - $a['v']);
$contratos = []; foreach ($act as $c) $contratos[$c['tipoContrato']] = ($contratos[$c['tipoContrato']] ?? 0) + 1; arsort($contratos);
$sedes = array_filter(tabla('sedes'), fn($s) => $s['activo'] && (!empresa_activa() || $s['empresaId'] === empresa_activa()));
$antig = ['Menos de 1 año' => 0, '1 a 3 años' => 0, '3 a 5 años' => 0, 'Más de 5 años' => 0];
foreach ($act as $c) { $a = dif_dias($c['fecIngreso'], HOY) / 365.25; $antig[$a < 1 ? 'Menos de 1 año' : ($a < 3 ? '1 a 3 años' : ($a < 5 ? '3 a 5 años' : 'Más de 5 años'))]++; }

$pagina['titulo'] = 'Personal';
$pagina['sub'] = pl(count($act), 'colaborador activo', 'colaboradores activos').' · '.count(array_filter($todos, fn($c) => $c['estado'] === 'Cesado')).' cesados en el registro';
$pagina['pestanas'] = pestanas([[url('personal'), 'Colaboradores', true], [url('organigrama'), 'Organigrama', false], [url('contratos'), 'Contratos', false], [url('cumpleanos'), 'Cumpleaños y aniversarios', false]]);
$pagina['acciones'] = '<a class="btn btn-sec" href="index.php?'.e(http_build_query(['exportar' => 'colaboradores'] + $base)).'">'.ic('download-simple').' Exportar</a>'
    .($editar ? '<a class="btn btn-sec" href="'.url('importar').'">'.ic('upload-simple').' Importar</a><a class="btn btn-primary" href="'.url('colaborador_form').'">'.ic('plus').' Nuevo colaborador</a>' : '');

/* Encabezado de columna ordenable */
$th = function (string $txt, string $clave, string $clase = '') use ($q, $vista, $enlace) {
    $def = $vista === 'cesados' ? 'cese' : ($vista === 'vencer' ? 'contrato' : 'nom');
    $ord = $q['ord'] ?? $def; $dir = $q['dir'] ?? ($vista === 'cesados' ? 'desc' : 'asc'); $on = $ord === $clave;
    $nuevo = $on && $dir === 'asc' ? 'desc' : 'asc';
    return '<th class="'.$clase.'" aria-sort="'.($on ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none').'"><a class="th-ord '.($on ? 'on' : '').'" data-parcial href="'.e($enlace(['ord' => $clave, 'dir' => $nuevo, 'pag' => ''])).'">'.$txt.' '.ic($on ? ($dir === 'asc' ? 'caret-up' : 'caret-down') : 'caret-up-down').'</a></th>';
};
$aviso = add_dias(HOY, (int)param('avisoContratoDias'));
$contrato = function (array $c) use ($aviso) {
    if ($c['tipoContrato'] === 'Indeterminado') return 'Indeterminado';
    $t = e($c['tipoContrato']).'<span class="sub2">hasta el '.f($c['finContrato']).'</span>';
    if ($c['estado'] === 'Activo' && $c['finContrato'] < HOY) return $t.' '.tag('Vencido', 'er', 'Venció '.hace($c['finContrato']));
    if ($c['estado'] === 'Activo' && $c['finContrato'] <= $aviso) return $t.' '.tag('Vence '.hace($c['finContrato']), 'wa');
    return $t;
};
?>
<section class="card" aria-labelledby="t-dist">
  <div class="card-header"><h2 id="t-dist">Equipo activo</h2><span class="ctx">Haga clic en un área para filtrar la lista</span></div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-lg-6"><h3 class="h-sec">Por área</h3><?= barras_h($dist) ?></div>
      <div class="col-lg-3 col-sm-6">
        <h3 class="h-sec">Por sede</h3>
        <ul class="lista lista-compacta">
          <?php foreach ($sedes as $s): $n = count(array_filter($act, fn($c) => $c['sedeId'] === $s['id'])); ?>
          <li><?= foto_sede($s, 'mini') ?><div class="min-w-0 flex-grow-1"><a href="<?= e($enlace(['sede' => $s['id'], 'vista' => 'activos', 'pag' => ''])) ?>"><?= e($s['nombre']) ?></a><span class="sub2"><?= e($s['ciudad']) ?></span></div><b><?= $n ?></b></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-lg-3 col-sm-6">
        <h3 class="h-sec">Por contrato</h3>
        <dl class="resumen mb-4"><?php foreach ($contratos as $k => $n): ?><dt><a href="<?= e($enlace(['contrato' => $k, 'vista' => 'activos', 'pag' => ''])) ?>"><?= e($k) ?></a></dt><dd class="text-nowrap"><?= $n ?> <span class="sub2 d-inline fw-normal">· <?= pct($n, count($act)) ?></span></dd><?php endforeach; ?></dl>
        <h3 class="h-sec">Por antigüedad</h3>
        <div class="saldo mb-2" role="img" aria-label="Distribución por antigüedad"><?php $tonos = ['#B9DCE3', '#6FB3C2', 'var(--g1)', 'var(--pe-700)']; $i = 0; foreach ($antig as $k => $n): ?><i style="width:<?= $act ? round($n / count($act) * 100, 1) : 0 ?>%;background:<?= $tonos[$i++] ?>" data-tip="<?= e($k.': '.$n) ?>"></i><?php endforeach; ?></div>
        <div class="leyenda"><?php $i = 0; foreach ($antig as $k => $n): ?><span><i style="background:<?= $tonos[$i++] ?>"></i><?= e($k) ?> · <?= $n ?></span><?php endforeach; ?></div>
      </div>
    </div>
  </div>
</section>

<section class="card" aria-labelledby="t-lista">
  <h2 id="t-lista" class="visually-hidden">Colaboradores</h2>
  <div class="card-header">
    <nav class="seg" aria-label="Vistas" id="seg-vistas" data-sincronizar>
      <?php foreach ($vistas as $k => [$txt, $fn]): $n = count(array_filter($todos, $fn)); ?>
      <a href="<?= e(url('personal', array_filter(['vista' => $k, 'modo' => $modo === 'tarjetas' ? 'tarjetas' : '']))) ?>" data-parcial class="<?= $vista === $k ? 'on' : '' ?>"><?= e($txt) ?><span class="n"><?= $n ?></span></a>
      <?php endforeach; ?>
    </nav>
    <nav class="seg" aria-label="Modo de presentación" id="seg-modo" data-sincronizar>
      <a href="<?= e($enlace(['modo' => ''])) ?>" data-parcial class="<?= $modo === 'tabla' ? 'on' : '' ?>" data-tip="Tabla" aria-label="Tabla"><?= ic('rows') ?></a>
      <a href="<?= e($enlace(['modo' => 'tarjetas'])) ?>" data-parcial class="<?= $modo === 'tarjetas' ? 'on' : '' ?>" data-tip="Tarjetas con foto" aria-label="Tarjetas con foto"><?= ic('squares-four') ?></a>
    </nav>
  </div>
  <form class="filtros" data-filtros role="search" action="index.php">
    <input type="hidden" name="p" value="personal">
    <input type="hidden" name="vista" value="<?= e($vista) ?>"><input type="hidden" name="modo" value="<?= $modo === 'tarjetas' ? 'tarjetas' : '' ?>">
    <input type="hidden" name="ord" value="<?= e($q['ord'] ?? '') ?>"><input type="hidden" name="dir" value="<?= e($q['dir'] ?? '') ?>">
    <label class="buscar"><?= ic('magnifying-glass') ?><span class="visually-hidden">Buscar</span><input type="search" class="form-control" name="q" value="<?= e($q['q'] ?? '') ?>" placeholder="Nombre, DNI, código o puesto" autocomplete="off"></label>
    <select class="form-select" name="area" aria-label="Área"><option value="">Todas las áreas</option><?= opciones($areas, $q['area'] ?? '', fn($a) => $a['nombre']) ?></select>
    <select class="form-select" name="sede" aria-label="Sede"><option value="">Todas las sedes</option><?= opciones($sedes, $q['sede'] ?? '', fn($s) => $s['nombre']) ?></select>
    <select class="form-select" name="contrato" aria-label="Tipo de contrato"><option value="">Todos los contratos</option><?= opciones_txt(['Indeterminado', 'Plazo fijo', 'Tiempo parcial'], $q['contrato'] ?? '') ?></select>
  </form>

  <div id="resultados" aria-live="polite">
    <?php $chips = array_filter(['q' => !empty($q['q']) ? '«'.$q['q'].'»' : '', 'area' => !empty($q['area']) ? (por_id('areas', (int)$q['area'])['nombre'] ?? '') : '', 'sede' => !empty($q['sede']) ? (por_id('sedes', (int)$q['sede'])['nombre'] ?? '') : '', 'contrato' => $q['contrato'] ?? '']);
    if ($chips): ?>
    <div class="chips"><span class="sub2 me-1"><?= pl($total, 'resultado', 'resultados') ?> para</span>
      <?php foreach ($chips as $k => $t): ?><span class="chip"><?= e($t) ?><a href="<?= e($enlace([$k => '', 'pag' => ''])) ?>" data-parcial aria-label="Quitar filtro <?= e($t) ?>"><?= ic('x') ?></a></span><?php endforeach; ?>
      <a class="small ms-1" href="<?= e(url('personal', array_filter(['vista' => $vista, 'modo' => $modo === 'tarjetas' ? 'tarjetas' : '']))) ?>" data-parcial>Limpiar filtros</a>
    </div>
    <?php endif; ?>

    <?php if ($vista === 'cesados' && !$chips):
      $meses = []; for ($i = 11; $i >= 0; $i--) { $ym = substr(add_meses(substr(HOY, 0, 7).'-15', -$i), 0, 7);
        $meses[] = ['et' => MES3[(int)substr($ym, 5) - 1], 'al' => count(array_filter($todos, fn($c) => substr($c['fecIngreso'], 0, 7) === $ym)), 'ba' => count(array_filter($todos, fn($c) => $c['fecCese'] && substr($c['fecCese'], 0, 7) === $ym))]; } ?>
    <div class="px-3 pt-3"><h3 class="h-sec">Altas y bajas de los últimos 12 meses</h3>
      <div class="table-responsive"><table class="table table-sm mb-3 tabla-mini"><thead><tr><th></th><?php foreach ($meses as $m): ?><th class="num"><?= $m['et'] ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
        <tbody><tr><td>Altas</td><?php foreach ($meses as $m): ?><td class="num"><?= $m['al'] ?: vacio_d() ?></td><?php endforeach; ?><td class="num fw-bold"><?= array_sum(array_column($meses, 'al')) ?></td></tr>
        <tr><td>Bajas</td><?php foreach ($meses as $m): ?><td class="num <?= $m['ba'] ? 'text-danger fw-bold' : '' ?>"><?= $m['ba'] ?: vacio_d() ?></td><?php endforeach; ?><td class="num fw-bold"><?= array_sum(array_column($meses, 'ba')) ?></td></tr></tbody></table></div></div>
    <?php endif; ?>

    <?php if ($editar && $modo === 'tabla'): ?>
    <div class="bulk" hidden><b data-sel-n></b>
      <button type="button" class="btn btn-sec btn-sm" data-bulk-panel="panel=masivo&campo=horarioId"><?= ic('clock') ?> Asignar horario</button>
      <button type="button" class="btn btn-sec btn-sm" data-bulk-panel="panel=masivo&campo=sedeId"><?= ic('map-pin') ?> Cambiar sede</button>
      <button type="button" class="btn btn-sec btn-sm" data-bulk-href="index.php?exportar=colaboradores&vista=<?= e($vista) ?>"><?= ic('download-simple') ?> Exportar selección</button>
      <button type="button" class="btn btn-ter btn-sm ms-auto" data-bulk-limpiar>Quitar selección</button>
    </div>
    <?php endif; ?>

    <?php if (!$lista): ?>
      <?= $chips ? estado_vacio('No hay colaboradores con esos filtros', 'Pruebe con otro nombre o quite algún filtro.', '<a class="btn btn-sec btn-sm" data-parcial href="'.e(url('personal', ['vista' => $vista])).'">Limpiar filtros</a>')
        : ($vista === 'incompletos' ? estado_vacio('Todas las fichas están completas', 'Ninguna ficha activa tiene datos pendientes.') : ($vista === 'vencer' ? estado_vacio('Ningún contrato vence pronto', 'No hay contratos que venzan en los próximos '.param('avisoContratoDias').' días.') : estado_vacio('Aún no hay colaboradores', 'Registre al primero o importe una lista desde Excel.', $editar ? '<a class="btn btn-primary btn-sm" href="'.url('colaborador_form').'">Nuevo colaborador</a>' : ''))) ?>
    <?php elseif ($modo === 'tarjetas'): ?>
    <div class="vista-tarjetas">
      <?php foreach ($visibles as $c): ?>
      <a class="tarjeta-colab" href="<?= url('colaborador', ['id' => $c['id']]) ?>">
        <?= avatar($c, 'l') ?>
        <b><?= e(nomc($c)) ?></b><span class="sub2"><?= e(puesto($c)) ?></span><span class="sub2"><?= e(area($c)) ?> · <?= e(sede($c)) ?></span>
        <?php if (incompleta($c)) echo tag('Ficha incompleta', 'wa'); elseif ($c['estado'] === 'Activo' && ($a = ausencia($c, HOY))) echo tag($a['tipo'].' hasta el '.f($a['hasta']), 'in'); ?>
        <span class="pie"><span><?= $c['estado'] === 'Cesado' ? 'Cesó '.e(hace($c['fecCese'])) : e(antiguedad($c['fecIngreso'])) ?></span><span><?= e($c['tipoContrato']) ?></span></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr>
          <?php if ($editar): ?><th style="width:36px"><input class="form-check-input" type="checkbox" data-sel-todo aria-label="Seleccionar todos los de esta página"></th><?php endif; ?>
          <?= $th('Colaborador', 'nom') ?><?= $th('Área y sede', 'area') ?><?= $th('Ingreso', 'ingreso') ?>
          <?= $vista === 'cesados' ? $th('Cese', 'cese') : $th('Contrato', 'contrato') ?>
          <th>Situación</th><th style="width:44px"><span class="visually-hidden">Acciones</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($visibles as $c): $a = $c['estado'] === 'Activo' ? ausencia($c, HOY) : null; ?>
          <tr data-href="<?= url('colaborador', ['id' => $c['id']]) ?>" data-menu>
            <?php if ($editar): ?><td><input class="form-check-input" type="checkbox" data-sel value="<?= $c['id'] ?>" aria-label="Seleccionar a <?= e(nom($c)) ?>"></td><?php endif; ?>
            <td><?= celda_nombre($c, e(puesto($c)).' · '.e($c['codigo'])) ?></td>
            <td><?= e(area($c)) ?><span class="sub2"><?= e(sede($c)) ?></span></td>
            <td><?= f($c['fecIngreso']) ?><span class="sub2"><?= e($c['estado'] === 'Activo' ? antiguedad($c['fecIngreso']) : '') ?></span></td>
            <td><?= $vista === 'cesados' ? f($c['fecCese']).'<span class="sub2">'.e($c['motivoCese']).'</span>' : $contrato($c) ?></td>
            <td><?php
              if ($c['estado'] === 'Cesado') echo estado('Cesado', 'Cesado '.hace($c['fecCese']));
              elseif ($a) echo estado('Pendiente', $a['tipo']).'<span class="sub2">hasta el '.f($a['hasta']).'</span>';
              elseif (incompleta($c)) echo tag('Ficha incompleta', 'wa', 'Falta '.implode(', ', array_filter([$c['direccion'] ? '' : 'dirección', $c['telefono'] ? '' : 'teléfono', $c['ccId'] ? '' : 'centro de costo'])));
              elseif (substr($c['fecIngreso'], 0, 7) === substr(HOY, 0, 7) || dif_dias($c['fecIngreso'], HOY) < 30) echo tag('Ingresó '.hace($c['fecIngreso']), 'in');
              else echo estado('Activo');
            ?></td>
            <td class="text-end"><div class="dropdown acc-fila">
              <button class="btn btn-ter btn-sm btn-ico" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acciones para <?= e(nomc($c)) ?>"><?= ic('dots-three-vertical') ?></button>
              <ul class="dropdown-menu dropdown-menu-end"><?= menu_colab($c) ?></ul>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($lista): ?>
    <div class="pag">
      <span>Mostrando <?= ($pag - 1) * $por + 1 ?>–<?= min($total, $pag * $por) ?> de <?= $total ?></span>
      <?php if ($paginas > 1): ?>
      <nav class="seg" aria-label="Páginas">
        <a href="<?= e($enlace(['pag' => max(1, $pag - 1)])) ?>" data-parcial aria-label="Página anterior" <?= $pag === 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>><?= ic('caret-left') ?></a>
        <?php for ($i = 1; $i <= $paginas; $i++): ?><a href="<?= e($enlace(['pag' => $i])) ?>" data-parcial class="<?= $i === $pag ? 'on' : '' ?>" <?= $i === $pag ? 'aria-current="page"' : '' ?>><?= $i ?></a><?php endfor; ?>
        <a href="<?= e($enlace(['pag' => min($paginas, $pag + 1)])) ?>" data-parcial aria-label="Página siguiente" <?= $pag === $paginas ? 'aria-disabled="true" tabindex="-1"' : '' ?>><?= ic('caret-right') ?></a>
      </nav>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
