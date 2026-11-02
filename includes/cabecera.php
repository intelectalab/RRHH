<?php
/**
 * cabecera.php — Inicio del documento HTML y barra superior (logo, empresa activa, buscador,
 * «Nuevo», ayuda, notificaciones y menú de la cuenta). Se incluye en todas las pantallas internas.
 */
$u = usuario(); $c_yo = yo(); $emp = por_id('empresas', empresa_activa());
$no_leidas = count(array_filter(tabla('notificaciones'), fn($n) => !$n['leida']));
?><!doctype html>
<html lang="es-PE">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(strip_tags($pagina['titulo'] ?? '')) ?> · AyniHR</title>
<link rel="icon" href="assets/img/favicon.svg">
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/phosphor/phosphor.css">
<link rel="stylesheet" href="assets/css/variables.css">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/componentes.css">
<link rel="stylesheet" href="assets/css/paginas.css">
<link rel="stylesheet" href="assets/css/editorial.css?v=1">
</head>
<body data-pagina="<?= e($p) ?>">
<a href="#contenido" class="saltar">Saltar al contenido</a>
<?php if (empty($pagina['sin_marco'])): ?>
<header class="topbar"><div class="in">
  <a class="logo" href="<?= url('inicio') ?>" aria-label="AyniHR, ir a Inicio"><?php include RAIZ.'/assets/img/logo-aynihr.svg'; ?><span><b>Ayni</b><span>HR</span></span></a>

  <?php if ((rol()['alcance'] ?? '') === 'T'): ?>
  <div class="dropdown">
    <button class="empsel" data-bs-toggle="dropdown" aria-label="Empresa activa: <?= $emp ? e($emp['razon']) : 'todas las empresas' ?>">
      <?= $emp ? logo_empresa($emp, 'sm') : '<span class="logo-emp sm todas">'.ic('buildings').'</span>' ?>
      <span class="t"><b><?= $emp ? e($emp['razon']) : 'Todas las empresas' ?></b><small><?= $emp ? 'RUC '.e($emp['ruc']) : pl(count(tabla('empresas')),'empresa','empresas').' · '.pl(count(tabla('sedes')),'sede','sedes') ?></small></span>
    </button>
    <ul class="dropdown-menu">
      <li><form method="post" action="index.php?accion=empresa"><?= campo_csrf() ?><input type="hidden" name="id" value="0"><button class="dropdown-item <?= !$emp ? 'active' : '' ?>"><span class="logo-emp sm todas"><?= ic('buildings') ?></span> Todas las empresas</button></form></li>
      <?php foreach (tabla('empresas') as $e) if ($e['activo']): ?>
      <li><form method="post" action="index.php?accion=empresa"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= $e['id'] ?>"><button class="dropdown-item <?= $emp && $emp['id'] === $e['id'] ? 'active' : '' ?>"><?= logo_empresa($e, 'sm') ?><span><?= e($e['razon']) ?><br><small class="text-secondary">RUC <?= e($e['ruc']) ?></small></span></button></form></li>
      <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (puede('personal.ver')): ?>
  <div class="gsearch" role="search">
    <?= ic('magnifying-glass') ?>
    <input class="form-control" id="buscador" placeholder="Buscar colaborador, DNI o puesto" autocomplete="off" aria-label="Buscar colaborador" aria-controls="buscador-res" aria-expanded="false">
    <kbd aria-hidden="true">/</kbd>
    <div class="res" id="buscador-res" role="listbox" hidden></div>
  </div>
  <?php else: ?><div class="flex-grow-1"></div><?php endif; ?>

  <div class="ms-auto d-flex align-items-center gap-1">
    <?php if (puede('personal.editar')): ?>
    <div class="dropdown">
      <button class="btn btn-primary btn-sm" data-bs-toggle="dropdown"><?= ic('plus') ?> Nuevo</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= url('colaborador_form') ?>"><?= ic('user-plus') ?> Colaborador</a></li>
        <?= item_panel('solicitud', [], ic('calendar-plus').' Solicitud de vacaciones o permiso') ?>
        <li><a class="dropdown-item" href="<?= url('asistencia') ?>"><?= ic('clock-clockwise') ?> Marcación manual</a></li>
      </ul>
    </div>
    <?php endif; ?>
    <button class="ibtn" data-panel="panel=ayuda&amp;de=<?= e($p) ?>" aria-label="Ayuda de esta pantalla" data-tip="Ayuda"><?= ic('question') ?></button>
    <div class="dropdown">
      <button class="ibtn" data-bs-toggle="dropdown" aria-label="Notificaciones<?= $no_leidas ? ', '.$no_leidas.' sin leer' : '' ?>" data-tip="Notificaciones"><?= ic('bell') ?><?php if ($no_leidas): ?><span class="cnt" aria-hidden="true"><?= $no_leidas ?></span><?php endif; ?></button>
      <div class="dropdown-menu dropdown-menu-end notifs">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom"><b>Notificaciones</b>
          <?php if ($no_leidas): ?><form method="post" action="index.php?accion=leer_todo"><?= campo_csrf() ?><button class="btn btn-ter btn-sm">Marcar todo como leído</button></form><?php endif; ?></div>
        <?php foreach (tabla('notificaciones') as $n): ?>
        <a class="dropdown-item notif <?= $n['leida'] ? '' : 'nueva' ?>" href="index.php?accion=leer&amp;id=<?= $n['id'] ?>&amp;csrf=<?= csrf() ?>"><span><?= e($n['txt']) ?><br><small><?= e(hace($n['fecha'])) ?></small></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="dropdown">
      <button class="userbtn" data-bs-toggle="dropdown" aria-label="Cuenta de <?= e($u['nombre']) ?>">
        <?= $c_yo ? avatar($c_yo) : '<span class="av" style="--av-bg:#E4E9EE;--av-fg:#3D4A57"><span class="ini">'.e(mb_strtoupper(mb_substr($u['usuario'],0,2))).'</span></span>' ?>
        <span><b><?= e($u['nombre']) ?></b><small><?= e(rol()['nombre'] ?? '') ?></small></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($c_yo): ?><li><a class="dropdown-item" href="<?= url('mi_ficha') ?>"><?= ic('identification-card') ?> Mi ficha</a></li><?php endif; ?>
        <li><a class="dropdown-item" href="<?= url('cambiar_clave') ?>"><?= ic('key') ?> Cambiar contraseña</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" action="index.php?accion=salir"><?= campo_csrf() ?><button class="dropdown-item"><?= ic('sign-out') ?> Cerrar sesión</button></form></li>
      </ul>
    </div>
  </div>
</div></header>
<div id="vista">
<?php include __DIR__.'/modulos.php'; ?>
<?php if (isset($pagina['encabezado_html'])) echo $pagina['encabezado_html']; else include __DIR__.'/encabezado.php'; ?>
<?php endif; ?>
<main class="<?= empty($pagina['sin_marco']) ? 'main' : '' ?>" id="contenido" tabindex="-1">
