<?php
/**
 * importar_marcaciones.php — Importación de marcaciones del reloj biométrico desde CSV.
 * Flujo: descargar plantilla → cargar el archivo → revisar vista previa → confirmar.
 */
if (!puede('asistencia.editar')) { echo estado_vacio('Sin acceso', 'Su perfil no tiene permiso para importar marcaciones.', 'ph-lock', ''); return; }

$imp    = $_SESSION['importar_marcaciones'] ?? null;
$validas = $imp ? count(array_filter($imp['filas'], fn($f) => !$f['err'])) : 0;

$pagina['titulo'] = 'Importar marcaciones';
$pagina['sub']    = 'Cargue un CSV del reloj biométrico con las marcaciones del período';
$pagina['migas']  = [['Asistencia', url('asistencia')], ['Importar marcaciones', '']];
?>
<ol class="pasos" aria-label="Pasos">
  <li class="<?= $imp ? 'hecho' : 'on' ?>"><span>Descargar plantilla</span></li>
  <li class="<?= $imp ? 'hecho' : '' ?>"><span>Cargar el archivo</span></li>
  <li class="<?= $imp ? 'on'   : '' ?>"><span>Revisar e importar</span></li>
</ol>

<?php if (!$imp): ?>
<div class="row g-4">
  <div class="col-lg-7">
    <form class="card mb-0" action="index.php?accion=importar_marcaciones" method="post" enctype="multipart/form-data" data-ajax novalidate>
      <?= campo_csrf() ?>
      <div class="card-header"><h2>Cargar el archivo</h2></div>
      <div class="card-body">
        <div class="panel-err" role="alert"></div>
        <label class="import-zona d-block">
          <?= ic('file-arrow-up', 'fs-1 d-block mb-2 text-secondary') ?>
          <b class="d-block">Seleccione el archivo CSV</b>
          <span class="sub2">Archivo exportado del reloj biométrico, en formato CSV. Hasta 1 000 filas.</span>
          <input type="file" name="archivo" accept=".csv,text/csv" class="form-control mt-3 mx-auto" style="max-width:360px">
        </label>
        <div class="invalid-feedback d-block" data-error="archivo"></div>
        <p class="sub2 mt-3 mb-0">Nada se guarda hasta que revise la vista previa y confirme. Si ya existe una marcación para ese día, se actualizará con los nuevos horarios.</p>
      </div>
      <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary">Revisar archivo</button></div>
    </form>
  </div>
  <div class="col-lg-5">
    <section class="bloque-plano">
      <div class="cab"><h2>Plantilla</h2></div>
      <p>Use la plantilla para que las columnas coincidan con el formato esperado.</p>
      <a class="btn btn-sec mb-4" href="index.php?exportar=plantilla_marcaciones"><?= ic('download-simple') ?> Descargar plantilla</a>
      <h3 class="h-sec">Columnas requeridas</h3>
      <ol class="sub2 ps-3 mb-3">
        <?php foreach (COLUMNAS_MARCACIONES as $col): ?><li><?= e($col) ?></li><?php endforeach; ?>
      </ol>
      <p class="sub2 mb-0">El separador puede ser punto y coma o coma. Si el reloj exporta en otro formato, adapte el archivo en Excel antes de cargarlo.</p>
    </section>
  </div>
</div>
<?php else: ?>
<section class="card">
  <div class="card-header"><h2>Vista previa · <?= e($imp['archivo']) ?></h2>
    <span class="ctx"><b class="text-success"><?= $validas ?> listas</b> · <b class="<?= count($imp['filas']) - $validas ? 'text-danger' : '' ?>"><?= count($imp['filas']) - $validas ?> con errores</b></span>
  </div>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th class="num">Fila</th><th>Colaborador</th><th>Fecha</th><th>Entrada</th><th>Salida</th><th>Resultado</th></tr></thead>
    <tbody>
    <?php foreach ($imp['filas'] as $f): $d = $f['datos']; ?>
      <tr class="<?= $f['err'] ? 'fila-alerta' : '' ?>">
        <td class="num sub2"><?= $f['n'] ?></td>
        <td><?php if ($d['colab']): ?><b><?= e(nomc($d['colab'])) ?></b><?php else: ?><span class="text-danger"><?= e('Sin colaborador') ?></span><?php endif; ?></td>
        <td><?= $d['fecha'] ? f($d['fecha']) : '<span class="text-danger">—</span>' ?></td>
        <td><?= e($d['entrada']) ?></td>
        <td><?= e($d['salida']) ?: '<span class="sub2">Sin salida</span>' ?></td>
        <td>
          <?php if ($f['err']): ?>
            <span class="st er">Con errores</span>
            <span class="sub2 text-danger"><?= e(implode('; ', $f['err'])) ?></span>
          <?php elseif ($d['existe']): ?>
            <span class="st pe" data-tip="Se actualizará la marcación existente">Actualizar</span>
          <?php else: ?>
            <?= estado('Activo', 'Nueva') ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-footer d-flex gap-2 align-items-center flex-wrap">
    <span class="sub2 d-inline me-auto"><?= count($imp['filas']) - $validas ? 'Las filas con errores no se importan. Corríjalas en el archivo y cárguelo de nuevo.' : 'Todas las filas son válidas.' ?></span>
    <form method="post" action="index.php?accion=importar_marcaciones_cancelar"><?= campo_csrf() ?><button class="btn btn-ter">Cargar otro archivo</button></form>
    <form method="post" action="index.php?accion=importar_marcaciones_confirmar" data-ajax><?= campo_csrf() ?><button class="btn btn-primary" <?= $validas ? '' : 'disabled' ?>>Importar <?= pl($validas, 'marcación', 'marcaciones') ?></button></form>
  </div>
</section>
<?php endif; ?>

