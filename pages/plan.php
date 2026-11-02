<?php
/**
 * plan.php — Uso del sistema: colaboradores activos, usuarios con acceso,
 * almacenamiento en base de datos y archivos, y evolución mensual.
 */
$act = count(array_filter(tabla('colaboradores'), fn($c) => $c['estado'] === 'Activo'));
$usr = count(array_filter(tabla('usuarios'), fn($u) => $u['activo']));

// Tamaño de la base de datos MySQL
$mb_bd = 0.0;
try {
    $st = db()->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb FROM information_schema.tables WHERE table_schema = DATABASE()");
    $mb_bd = (float)($st->fetchColumn() ?: 0.0);
} catch (Exception $e) {}

// Tamaño de archivos almacenados
$tam_archivos = 0;
if (is_dir(DIR_ARCHIVOS)) {
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DIR_ARCHIVOS, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) $tam_archivos += $f->getSize();
        }
    } catch (Exception $e) {}
}
$mb_arch = round($tam_archivos / 1048576, 2);

$todos = tabla('colaboradores');
$serie = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = substr(add_meses(substr(HOY, 0, 7).'-15', -$i), 0, 7);
    $v = plantilla_al(min(fin_mes($ym), HOY), $todos);
    $serie[] = ['et' => MES3[(int)substr($ym, 5) - 1], 'v' => $v, 'tip' => mes_nombre((int)substr($ym, 5)).' '.substr($ym, 0, 4).': '.$v.' colaboradores activos'];
}

$pagina['titulo'] = 'Configuración';
$pagina['sub'] = 'Uso del sistema y almacenamiento';
$pagina['pestanas'] = pestanas_config('plan');
?>
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <section class="card mb-0"><div class="card-body">
      <span class="sub2">Colaboradores activos</span>
      <div class="d-flex align-items-baseline gap-2 my-2">
        <span class="heroe" style="font-size:36px;line-height:42px"><?= $act ?></span>
      </div>
      <p class="sub2 mb-0">Personal en planilla activa actualmente.</p>
    </div></section>
  </div>
  <div class="col-md-3">
    <section class="card mb-0"><div class="card-body">
      <span class="sub2">Usuarios con acceso</span>
      <div class="d-flex align-items-baseline gap-2 my-2">
        <span class="heroe" style="font-size:36px;line-height:42px"><?= $usr ?></span>
      </div>
      <p class="sub2 mb-0">Cuentas activas en la plataforma.</p>
    </div></section>
  </div>
  <div class="col-md-3">
    <section class="card mb-0"><div class="card-body">
      <span class="sub2">Base de datos</span>
      <div class="d-flex align-items-baseline gap-2 my-2">
        <span class="heroe" style="font-size:36px;line-height:42px"><?= num($mb_bd, 2) ?></span>
        <span class="sub2 d-inline">MB</span>
      </div>
      <p class="sub2 mb-0">Tablas, índices y auditoría.</p>
    </div></section>
  </div>
  <div class="col-md-3">
    <section class="card mb-0"><div class="card-body">
      <span class="sub2">Documentos adjuntos</span>
      <div class="d-flex align-items-baseline gap-2 my-2">
        <span class="heroe" style="font-size:36px;line-height:42px"><?= num($mb_arch, 2) ?></span>
        <span class="sub2 d-inline">MB</span>
      </div>
      <p class="sub2 mb-0">Archivos PDF e imágenes en disco.</p>
    </div></section>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-12">
    <section class="card mb-0">
      <div class="card-header">
        <h2>Colaboradores activos por mes</h2>
        <span class="ctx">Últimos 12 meses</span>
      </div>
      <div class="card-body">
        <?= linea($serie, ['alto' => 200, 'min' => 0, 'etiqueta_final' => $act.' activos', 'cada' => 2, 'titulo' => 'Colaboradores activos por mes']) ?>
      </div>
    </section>
  </div>
</div>
