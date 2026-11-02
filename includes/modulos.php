<?php
/**
 * modulos.php — Barra de módulos (menú principal). Edite aquí para agregar o quitar módulos.
 * Cada módulo: [clave, texto, permiso requerido, pantalla alternativa si no tiene el permiso, texto alternativo].
 */
$MODULOS = [
    ['inicio',        'Inicio',                  null,                  null,              null],
    ['personal',      'Personal',                'personal.ver',        'mi_ficha',        'Mi ficha'],
    ['asistencia',    'Asistencia',              'asistencia.ver',      'mis_marcaciones', 'Mis marcaciones'],
    ['vacaciones',    'Vacaciones y ausencias',  'vacaciones.ver',      'mis_vacaciones',  'Mis vacaciones'],
    ['aprobaciones',  'Aprobaciones',            'vacaciones.aprobar',  null,              null],
    ['organizacion',  'Organización',            'organizacion.editar', null,              null],
    ['configuracion', 'Configuración',           'config.usuarios',     null,              null],
];
$activo = MODULO_DE[$p] ?? $p;
$pendientes = count(array_filter(tabla('vacaciones'), fn($v) => $v['estado'] === 'Pendiente' && in_array($v['colabId'], array_column(colabs_visibles(), 'id'), true)));
?>
<nav class="modbar" aria-label="Módulos">
<div class="nav-caption">ESPACIO DE TRABAJO</div><div class="in">
<?php foreach ($MODULOS as [$k, $txt, $perm, $alt, $alt_txt]):
    $ok = !$perm || puede($perm) || ($k === 'configuracion' && puede('config.auditoria'));
    if (!$ok && !$alt) continue;
    $destino = $ok ? $k : $alt; ?>
  <a href="<?= url($destino) ?>" class="<?= $activo === $k ? 'on' : '' ?>" <?= $activo === $k ? 'aria-current="page"' : '' ?>><?= ic(['inicio'=>'house','personal'=>'users','asistencia'=>'clock','vacaciones'=>'calendar-blank','aprobaciones'=>'checks','organizacion'=>'buildings','configuracion'=>'gear'][$k]) ?><span class="nav-label"><?= e($ok ? $txt : $alt_txt) ?></span><?php if ($k === 'aprobaciones' && $ok && $pendientes): ?> <span class="cnt-mod" data-contador="ocultar" aria-label="<?= $pendientes ?> pendientes"><?= $pendientes ?></span><?php endif; ?></a>
<?php endforeach; ?>
</div><div class="nav-footer"><span class="nav-mark">AyniHR</span><span>Gestión de personal</span><small>Personas · Equipos · Organización</small></div></nav>
