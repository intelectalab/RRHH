<?php
/**
 * importar.php — Importación de colaboradores desde Excel: descargar plantilla, cargar el CSV,
 * revisar la vista previa con los errores de cada fila e importar solo las filas válidas.
 */
$imp = $_SESSION['importar'] ?? null;
$pagina['titulo'] = 'Importar colaboradores';
$pagina['sub'] = 'Desde una hoja de Excel guardada como CSV (separado por punto y coma)';
$pagina['migas'] = [['Personal', url('personal')], ['Importar', '']];
$validas = $imp ? count(array_filter($imp['filas'], fn($f) => !$f['err'])) : 0;
?>
<ol class="pasos" aria-label="Pasos">
  <li class="<?= $imp ? 'hecho' : 'on' ?>"><span>Descargar la plantilla</span></li>
  <li class="<?= $imp ? 'hecho' : '' ?>"><span>Cargar el archivo</span></li>
  <li class="<?= $imp ? 'on' : '' ?>"><span>Revisar e importar</span></li>
</ol>

<?php if (!$imp): ?>
<div class="row g-4">
  <div class="col-lg-7">
    <form class="card mb-0" action="index.php?accion=importar" method="post" enctype="multipart/form-data" data-ajax novalidate>
      <?= campo_csrf() ?>
      <div class="card-header"><h2>Cargar el archivo</h2></div>
      <div class="card-body">
        <div class="panel-err" role="alert"></div>
        <label class="import-zona d-block">
          <?= ic('file-arrow-up', 'fs-1 d-block mb-2 text-secondary') ?>
          <b class="d-block">Seleccione el archivo CSV</b>
          <span class="sub2">En Excel: Archivo › Guardar como › CSV UTF-8 (delimitado por comas). Hasta 500 filas.</span>
          <input type="file" name="archivo" accept=".csv,text/csv" class="form-control mt-3 mx-auto" style="max-width:360px">
        </label>
        <div class="invalid-feedback d-block" data-error="archivo"></div>
        <p class="sub2 mt-3 mb-0">Nada se guarda hasta que revise la vista previa y confirme.</p>
      </div>
      <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary">Revisar archivo</button></div>
    </form>
  </div>
  <div class="col-lg-5">
    <section class="bloque-plano">
      <div class="cab"><h2>Plantilla</h2></div>
      <p>Use la plantilla para que las columnas coincidan. Incluye una fila de ejemplo que puede borrar.</p>
      <a class="btn btn-sec mb-4" href="index.php?exportar=plantilla"><?= ic('download-simple') ?> Descargar plantilla</a>
      <h3 class="h-sec">Columnas</h3>
      <ol class="sub2 ps-3 mb-3 columnas-2"><?php foreach (COLUMNAS_IMPORTAR as $col): ?><li><?= e($col) ?></li><?php endforeach; ?></ol>
      <p class="sub2 mb-0">Sede, área, puesto y horario deben escribirse igual que en Organización. Las fechas, en formato dd/mm/aaaa.</p>
    </section>
  </div>
</div>
<?php else: ?>
<section class="card">
  <div class="card-header"><h2>Vista previa · <?= e($imp['archivo']) ?></h2>
    <span class="ctx"><b class="text-success"><?= $validas ?> listas</b> · <b class="<?= count($imp['filas']) - $validas ? 'text-danger' : '' ?>"><?= count($imp['filas']) - $validas ?> con errores</b></span></div>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th class="num">Fila</th><th>Colaborador</th><th>DNI</th><th>Sede · área · puesto</th><th>Ingreso</th><th>Resultado</th></tr></thead>
    <tbody>
    <?php foreach ($imp['filas'] as $f): $d = $f['datos']; ?>
      <tr class="<?= $f['err'] ? 'fila-alerta' : '' ?>">
        <td class="num sub2"><?= $f['n'] ?></td>
        <td><b><?= e($d['apPat'].' '.$d['apMat'].', '.$d['nombres']) ?></b></td><td><?= e($d['nroDoc']) ?></td>
        <td><?= e(implode(' · ', array_filter([por_id('sedes', $d['sedeId'])['nombre'] ?? '', por_id('areas', $d['areaId'])['nombre'] ?? '', por_id('puestos', $d['puestoId'])['nombre'] ?? '']))) ?: vacio_d() ?></td>
        <td><?= f($d['fecIngreso']) ?: vacio_d() ?></td>
        <td><?= $f['err'] ? '<span class="st er">Con errores</span><span class="sub2 text-danger">'.e(implode('; ', $f['err'])).'</span>' : estado('Activo', 'Lista') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="card-footer d-flex gap-2 align-items-center flex-wrap">
    <span class="sub2 d-inline me-auto"><?= count($imp['filas']) - $validas ? 'Las filas con errores no se importan. Corríjalas en el archivo y cárguelo de nuevo.' : 'Todas las filas son válidas.' ?></span>
    <form method="post" action="index.php?accion=importar_cancelar"><?= campo_csrf() ?><button class="btn btn-ter">Cargar otro archivo</button></form>
    <form method="post" action="index.php?accion=importar_confirmar" data-ajax><?= campo_csrf() ?><button class="btn btn-primary" <?= $validas ? '' : 'disabled' ?>>Importar <?= pl($validas, 'colaborador', 'colaboradores') ?></button></form>
  </div>
</section>
<?php endif; ?>
