<?php
/**
 * usuarios.php — Usuarios con acceso al sistema: perfil, colaborador vinculado, último ingreso y estado.
 */
$us = tabla('usuarios'); usort($us, fn($a, $b) => $b['activo'] - $a['activo'] ?: strcmp($a['nombre'], $b['nombre']));
$roles = []; foreach (tabla('roles') as $r) $roles[$r['id']] = $r;
$pagina['titulo'] = 'Configuración';
$pagina['sub'] = pl(count(array_filter($us, fn($u) => $u['activo'])), 'usuario activo', 'usuarios activos').' de '.count($us);
$pagina['pestanas'] = pestanas_config('usuarios');
$pagina['acciones'] = btn_panel('usuario', [], ic('plus').' Nuevo usuario', 'btn btn-primary');
$porRol = []; foreach ($us as $u) if ($u['activo']) $porRol[$u['rolId']] = ($porRol[$u['rolId']] ?? 0) + 1;
?>
<div class="row g-4">
  <div class="col-xl-9">
    <section class="card mb-0">
      <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Usuario</th><th>Perfil</th><th>Colaborador vinculado</th><th>Último ingreso</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($us as $u): $c = por_id('colaboradores', (int)$u['colabId']); $r = $roles[$u['rolId']] ?? null; $yo = $u['id'] === usuario()['id']; ?>
          <tr class="<?= $u['activo'] ? '' : 'text-secondary' ?>">
            <td><div class="who"><?= $c ? avatar($c) : '<span class="av" style="--av-bg:#E4E9EE;--av-fg:#55616E" aria-hidden="true"><span class="ini">'.ic('user').'</span></span>' ?><div class="min-w-0"><b class="d-block"><?= e($u['nombre']) ?><?= $yo ? ' <span class="tag in">Usted</span>' : '' ?></b><span class="sub2"><?= e($u['usuario']) ?></span></div></div></td>
            <td><?= e($r['nombre'] ?? '') ?><span class="sub2"><?= e(ALCANCES[$r['alcance'] ?? 'P']) ?></span></td>
            <td><?= $c ? '<a href="'.url('colaborador', ['id' => $c['id']]).'">'.e(nom($c)).'</a><span class="sub2">'.e(puesto($c)).'</span>' : '<span class="sub2">Sin vincular · acceso externo</span>' ?></td>
            <td><?= $u['ultimo'] ? cuando($u['ultimo']) : '<span class="sub2">Nunca</span>' ?></td>
            <td><?= $u['activo'] ? estado('Activo') : estado('Anulada', 'Desactivado') ?></td>
            <td class="text-end text-nowrap">
              <?= btn_panel('usuario', ['id' => $u['id']], 'Editar', 'btn btn-ter btn-sm') ?>
              <?= btn_panel('confirmar', ['que' => 'reset_clave', 'id' => $u['id']], 'Restablecer contraseña', 'btn btn-ter btn-sm') ?>
              <?= $yo ? '' : btn_panel('confirmar', ['que' => 'estado_usuario', 'id' => $u['id']], $u['activo'] ? 'Desactivar' : 'Activar', 'btn btn-ter btn-sm') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
  </div>
  <div class="col-xl-3">
    <section class="bloque-plano"><div class="cab"><h2>Por perfil</h2></div>
      <dl class="resumen"><?php foreach ($roles as $r): ?><dt><a href="<?= url('perfiles') ?>"><?= e($r['nombre']) ?></a></dt><dd><?= $porRol[$r['id']] ?? 0 ?></dd><?php endforeach; ?></dl>
      <p class="sub2 mt-3">Al registrar el cese de un colaborador se desactiva su usuario automáticamente. Siempre debe quedar al menos un administrador activo.</p>
    </section>
  </div>
</div>
