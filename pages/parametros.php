<?php
/**
 * parametros.php — Parámetros generales: días de vacaciones por año, mínimo por solicitud,
 * anticipación del aviso de contratos y filas por página. Sección de feriados oficiales.
 */
$pr = tabla('parametros')[0];
$feriados = tabla('feriados');
usort($feriados, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

$pagina['titulo'] = 'Configuración';
$pagina['sub'] = 'Valores que usan los cálculos de vacaciones, avisos, listas y feriados oficiales';
$pagina['pestanas'] = pestanas_config('parametros');
$campos = [
    ['diasVacAnio', 'Días de vacaciones por año de servicio', 'días', 'D. L. 713 · pendiente de validación legal', true],
    ['diasMinVac', 'Mínimo de días por solicitud de vacaciones', 'días', 'Fraccionamiento según D. L. 1405 · pendiente de validación legal', true],
    ['avisoContratoDias', 'Aviso de vencimiento de contratos', 'días antes', 'Se usa en Inicio, Personal y Contratos', false],
    ['filasPagina', 'Filas por página en las listas', 'filas', '', false],
];
?>
<div class="row g-4">
  <div class="col-xl-7">
    <form class="card mb-0" method="post" action="index.php?accion=parametros" data-ajax novalidate><?= campo_csrf() ?>
      <div class="card-header"><h2>Parámetros generales</h2></div>
      <div class="card-body"><div class="panel-err" role="alert"></div>
        <?php foreach ($campos as [$k, $l, $u, $nota, $legal]): ?>
        <div class="row align-items-center mb-3 g-2">
          <label class="col-sm-6 form-label mb-0" for="p-<?= $k ?>"><?= e($l) ?><?= $nota ? '<span class="sub2 fw-normal '.($legal ? 'text-warning-emphasis' : '').'">'.e($nota).'</span>' : '' ?></label>
          <div class="col-sm-6"><div class="input-group" style="max-width:220px"><input class="form-control" type="number" min="1" max="365" id="p-<?= $k ?>" name="<?= $k ?>" value="<?= (int)$pr[$k] ?>"><span class="input-group-text"><?= e($u) ?></span></div><div class="invalid-feedback d-block" data-error="<?= $k ?>"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary">Guardar parámetros</button></div>
    </form>
  </div>
  <div class="col-xl-5">
    <section class="bloque-plano"><div class="cab"><h2>Antes de cambiar</h2></div>
      <ul class="ps-3 sub2 d-block">
        <li class="mb-2">Los días de vacaciones recalculan los saldos de todo el personal de inmediato.</li>
        <li class="mb-2">Los valores marcados como «pendiente de validación legal» deben confirmarse con el asesor laboral antes de usar el sistema en producción.</li>
        <li>Cada cambio queda en la auditoría con el valor anterior y el nuevo.</li>
      </ul>
    </section>
  </div>
</div>

<div class="row g-4 mt-1">
  <div class="col-xl-12">
    <section class="card mb-0">
      <div class="card-header">
        <h2>Feriados oficiales</h2>
        <span class="ctx"><?= pl(count($feriados), 'día no laborable registrado', 'días no laborables registrados') ?></span>
      </div>
      <div class="card-body border-bottom bg-light">
        <form method="post" action="index.php?accion=agregar_feriado" class="row g-2 align-items-end">
          <?= campo_csrf() ?>
          <div class="col-md-3">
            <label class="form-label small" for="f-fecha">Fecha (dd/mm/aaaa)</label>
            <input type="text" class="form-control form-control-sm" id="f-fecha" name="fecha" placeholder="01/01/2026" required data-fecha>
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="f-nombre">Nombre del feriado</label>
            <input type="text" class="form-control form-control-sm" id="f-nombre" name="nombre" placeholder="Por ejemplo: Año Nuevo" required>
          </div>
          <div class="col-md-3">
            <button class="btn btn-primary btn-sm w-100"><?= ic('plus') ?> Agregar feriado</button>
          </div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Día</th>
              <th>Nombre del feriado</th>
              <th>Año</th>
              <th class="text-end" style="width:100px;">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$feriados): ?>
              <tr><td colspan="5" class="text-center text-secondary py-3">No hay feriados registrados.</td></tr>
            <?php else: foreach ($feriados as $f): ?>
              <tr>
                <td><b><?= f($f['fecha']) ?></b></td>
                <td><span class="sub2"><?= ucfirst(DIAS[dia_sem($f['fecha'])]) ?></span></td>
                <td><?= e($f['nombre']) ?></td>
                <td><span class="badge bg-secondary"><?= substr($f['fecha'], 0, 4) ?></span></td>
                <td class="text-end">
                  <form method="post" action="index.php?accion=quitar_feriado" onsubmit="return confirm('¿Desea quitar este feriado?');" class="d-inline">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                    <button class="btn btn-ter btn-sm text-danger" title="Quitar feriado"><?= ic('trash') ?> Quitar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
