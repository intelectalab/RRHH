<?php
/**
 * auditoria.php — Registro de acciones agrupado por día (Hoy, Ayer, …), con filtros por tipo,
 * usuario y texto. Las consultas a datos sensibles quedan resaltadas.
 */
$au = tabla('auditoria');
$tipos = ['' => 'Todo', 'sesion' => 'Sesiones', 'personal' => 'Personal', 'asistencia' => 'Asistencia', 'vacaciones' => 'Vacaciones', 'config' => 'Configuración', 'sensible' => 'Datos sensibles'];
$clasif = function (array $a): string {
    $t = mb_strtolower($a['accion']);
    if ($a['sens']) return 'sensible';
    if (str_contains($t, 'sesión')) return 'sesion';
    if (preg_match('/marcaci/', $t)) return 'asistencia';
    if (preg_match('/solicitud|aprobaci|rechazo|anulaci|vacacion|permiso$|descanso/', $t) && !str_contains($t, 'perfil')) return 'vacaciones';
    if (preg_match('/usuario|perfil|parámetro|permiso|alcance|empresa|sede|área|puesto|horario|centro|archivo|restauraci|registro en|edición en/', $t)) return 'config';
    return 'personal';
};
$tipo = $_GET['tipo'] ?? ''; if (!isset($tipos[$tipo])) $tipo = '';
$q = mb_strtolower(trim($_GET['q'] ?? '')); $usu = $_GET['usuario'] ?? '';
$lista = array_values(array_filter($au, fn($a) => (!$tipo || $clasif($a) === $tipo) && (!$usu || $a['usuario'] === $usu) && ($q === '' || str_contains(mb_strtolower($a['accion'].' '.$a['detalle'].' '.$a['usuario']), $q))));
$lista = array_slice($lista, 0, 300);
$porDia = []; foreach ($lista as $a) $porDia[substr($a['fecha'], 0, 10)][] = $a;
$usuarios = array_values(array_unique(array_column($au, 'usuario'))); sort($usuarios);
$ic = ['sesion' => 'sign-in', 'personal' => 'user', 'asistencia' => 'clock', 'vacaciones' => 'airplane-tilt', 'config' => 'gear-six', 'sensible' => 'lock-simple'];

$pagina['titulo'] = 'Configuración';
$pagina['sub'] = 'Registro de acciones · '.pl(count($au), 'evento', 'eventos');
$pagina['pestanas'] = pestanas_config('auditoria');
?>
<section class="card">
  <div class="card-header">
    <nav class="seg" aria-label="Tipo"><?php foreach ($tipos as $k => $t): ?><a href="<?= url('auditoria', array_filter(['tipo' => $k, 'usuario' => $usu, 'q' => $_GET['q'] ?? ''])) ?>" class="<?= $tipo === $k ? 'on' : '' ?>"><?= e($t) ?></a><?php endforeach; ?></nav>
  </div>
  <form class="filtros" action="index.php" role="search"><input type="hidden" name="p" value="auditoria"><input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <label class="buscar"><?= ic('magnifying-glass') ?><span class="visually-hidden">Buscar</span><input type="search" class="form-control" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Acción, detalle o código"></label>
    <select class="form-select" name="usuario" aria-label="Usuario" onchange="this.form.submit()"><option value="">Todos los usuarios</option><?php foreach ($usuarios as $u): ?><option <?= $usu === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sec">Buscar</button>
  </form>
  <?php if (!$lista): ?><?= estado_vacio('No hay eventos con esos filtros') ?>
  <?php else: foreach ($porDia as $d => $items): ?>
    <div class="aud-dia"><?= e(ucfirst($d === HOY ? 'hoy' : ($d === add_dias(HOY, -1) ? 'ayer' : fecha_larga($d)))) ?> · <?= count($items) ?></div>
    <?php foreach ($items as $a): $k = $clasif($a); ?>
    <div class="aud-item <?= $a['sens'] ? 'sens' : '' ?>">
      <span class="h"><?= e(substr($a['fecha'], 11, 5)) ?></span>
      <span class="d-flex gap-2 align-items-center min-w-0"><span class="aud-ic <?= $k ?>"><?= ic($ic[$k]) ?></span><b class="text-truncate"><?= e($a['usuario']) ?></b></span>
      <span class="min-w-0"><b><?= e($a['accion']) ?></b><?= $a['sens'] ? ' '.tag('Dato sensible', 'wa') : '' ?><span class="sub2 text-break"><?= e($a['detalle']) ?></span></span>
    </div>
    <?php endforeach; ?>
  <?php endforeach; endif; ?>
  <?php if (count($lista) === 300): ?><div class="pag">Se muestran los 300 eventos más recientes. Use los filtros para acotar.</div><?php endif; ?>
</section>
