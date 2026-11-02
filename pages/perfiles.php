<?php
/**
 * perfiles.php — Matriz de permisos por perfil. Cada interruptor se guarda al instante (sin recargar)
 * y queda en la auditoría. El perfil Administrador no se puede modificar.
 */
$roles = tabla('roles');
$us = tabla('usuarios');
$pagina['titulo'] = 'Configuración';
$pagina['sub'] = 'Qué puede ver y hacer cada perfil, y sobre qué personas';
$pagina['pestanas'] = pestanas_config('perfiles');
$pagina['acciones'] = btn_panel('perfil', [], ic('plus').' Nuevo perfil', 'btn btn-primary');
$grupo = '';
?>
<section class="card">
  <div class="table-responsive"><table class="table matriz-perm mb-0">
    <thead><tr><th style="min-width:280px">Permiso</th>
      <?php foreach ($roles as $r): $n = count(array_filter($us, fn($u) => $u['rolId'] === $r['id'] && $u['activo'])); ?>
      <th style="min-width:130px"><?= e($r['nombre']) ?><span class="sub2 fw-normal"><?= pl($n, 'usuario', 'usuarios') ?></span></th>
      <?php endforeach; ?></tr></thead>
    <tbody>
      <tr><td><b>Alcance</b><span class="sub2">Sobre qué personas aplica el perfil</span></td>
        <?php foreach ($roles as $r): ?><td>
          <?php if ($r['id'] === 1): ?><span class="small"><?= e(ALCANCES[$r['alcance']]) ?></span>
          <?php else: ?><form method="post" action="index.php?accion=alcance" data-ajax data-auto><?= campo_csrf() ?><input type="hidden" name="rol" value="<?= $r['id'] ?>">
            <select class="form-select form-select-sm" name="alcance" aria-label="Alcance de <?= e($r['nombre']) ?>"><?php foreach (ALCANCES as $k => $t): ?><option value="<?= $k ?>" <?= $r['alcance'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></form>
          <?php endif; ?></td><?php endforeach; ?></tr>
    <?php foreach (PERMISOS as [$k, $g, $t]): if ($g !== $grupo): $grupo = $g; ?>
      <tr class="fila-grupo"><td colspan="<?= count($roles) + 1 ?>"><?= e($g) ?></td></tr>
    <?php endif; ?>
      <tr><td><?= e($t) ?><?= in_array($k, ['personal.sueldo', 'personal.sensible'], true) ? ' '.tag('Dato sensible', 'wa') : '' ?></td>
        <?php foreach ($roles as $r): $on = in_array($k, $r['permisos'], true); ?>
        <td><?php if ($r['id'] === 1): ?><span class="text-success" aria-label="Permitido"><?= ic('check-circle', 'fs-5') ?></span>
          <?php else: ?><form method="post" action="index.php?accion=permiso" data-ajax data-auto class="form-check form-switch"><?= campo_csrf() ?><input type="hidden" name="rol" value="<?= $r['id'] ?>"><input type="hidden" name="permiso" value="<?= e($k) ?>"><input type="hidden" name="on" value="0">
            <input class="form-check-input" type="checkbox" role="switch" name="on" value="1" <?= $on ? 'checked' : '' ?> aria-label="<?= e($t.' · '.$r['nombre']) ?>"></form>
          <?php endif; ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="card-footer sub2"><?= ic('info') ?> Los cambios se aplican desde la siguiente acción de cada usuario. El perfil Administrador tiene todos los permisos y no se puede modificar.</div>
</section>
