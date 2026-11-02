<?php
/**
 * colaborador_form.php — Alta y edición de colaborador en cuatro pasos (datos personales, contacto,
 * datos laborales, pensión y banco). Se envía sin recargar; si hay errores, vuelve al paso que corresponde.
 * En la edición no se cambian ingreso, contrato ni sueldo: eso se hace con «Registrar movimiento».
 */
$id = (int)($_GET['id'] ?? 0); $c = $id ? por_id('colaboradores', $id) : [];
if ($id && (!$c || !puede_ver_colab($c))) { $pagina['titulo'] = 'Colaborador no encontrado'; echo estado_vacio('No se encontró al colaborador'); return; }
$v = fn($k, $def = '') => $c[$k] ?? $def;
$emp0 = $v('empresaId', empresa_activa() ?: 1);

$pagina['titulo'] = $id ? 'Editar datos de '.nomc($c) : 'Nuevo colaborador';
$pagina['sub'] = $id ? e($c['codigo'].' · '.puesto($c)) : 'Complete los cuatro pasos. Los campos marcados son obligatorios.';
$pagina['migas'] = [['Personal', url('personal')], $id ? [nom($c), url('colaborador', ['id' => $id])] : null, [$id ? 'Editar' : 'Nuevo colaborador', '']];
$pagina['migas'] = array_values(array_filter($pagina['migas']));

$req = 'required';
$sel = fn($k, $l, $ops, $extra = '') => p_combo($k, $l, $ops, $extra);
$activosDe = fn($t) => array_filter(tabla($t), fn($x) => $x['activo']);
$sedesOps = '<option value="">Seleccione</option>'; foreach ($activosDe('sedes') as $s) $sedesOps .= '<option value="'.$s['id'].'" data-padre="'.$s['empresaId'].'" '.($v('sedeId') == $s['id'] ? 'selected' : '').'>'.e($s['nombre']).'</option>';
$puestosOps = '<option value="">Seleccione</option>'; foreach ($activosDe('puestos') as $x) $puestosOps .= '<option value="'.$x['id'].'" data-padre="'.$x['areaId'].'" '.($v('puestoId') == $x['id'] ? 'selected' : '').'>'.e($x['nombre']).'</option>';
$jefes = array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && $x['id'] !== $id); usort($jefes, fn($a, $b) => strcmp(nom($a), nom($b)));
$foto = $id ? $c['foto'] : '';
?>
<div class="row g-4">
  <div class="col-xl-8">
    <form class="card" action="index.php?accion=guardar_colaborador" method="post" data-ajax data-wizard novalidate>
      <?= campo_csrf() ?><input type="hidden" name="id" value="<?= $id ?>">
      <div class="card-body pb-0">
        <ol class="pasos">
          <li data-ir-paso="1"><span>Datos personales</span></li><li data-ir-paso="2"><span>Contacto</span></li><li data-ir-paso="3"><span>Datos laborales</span></li><li data-ir-paso="4"><span>Pensión y banco</span></li>
        </ol>
        <div class="panel-err" role="alert"></div>
      </div>
      <div class="card-body pt-0">
        <fieldset class="paso" data-paso="1"><legend class="visually-hidden">Datos personales</legend>
          <div class="row">
            <div class="col-md-4"><?= p_campo('apPat', 'Apellido paterno', 'text', $v('apPat'), $req.' autocomplete="off"') ?></div>
            <div class="col-md-4"><?= p_campo('apMat', 'Apellido materno', 'text', $v('apMat'), $req.' autocomplete="off"') ?></div>
            <div class="col-md-4"><?= p_campo('nombres', 'Nombres', 'text', $v('nombres'), $req.' autocomplete="off"') ?></div>
            <div class="col-md-4"><?= $sel('tipoDoc', 'Tipo de documento', opciones_txt(['DNI', 'Carné de extranjería', 'Pasaporte'], $v('tipoDoc', 'DNI'))) ?></div>
            <div class="col-md-4"><?= p_campo('nroDoc', 'Número de documento', 'text', $v('nroDoc'), $req.' inputmode="numeric" maxlength="12"', 'DNI: 8 dígitos') ?></div>
            <div class="col-md-4"><?= p_fecha('fecNac', 'Fecha de nacimiento', $v('fecNac')) ?></div>
            <div class="col-md-4"><?= $sel('sexo', 'Sexo', '<option value="F" '.($v('sexo') === 'F' ? 'selected' : '').'>Femenino</option><option value="M" '.($v('sexo') === 'M' ? 'selected' : '').'>Masculino</option>') ?></div>
            <div class="col-md-4"><?= $sel('estadoCivil', 'Estado civil', opciones_txt(['Soltero(a)', 'Casado(a)', 'Conviviente', 'Divorciado(a)', 'Viudo(a)'], $v('estadoCivil', 'Soltero(a)'))) ?></div>
          </div>
        </fieldset>
        <fieldset class="paso" data-paso="2" hidden><legend class="visually-hidden">Contacto</legend>
          <div class="row">
            <div class="col-md-6"><?= p_campo('telefono', 'Celular <span class="op">(opcional)</span>', 'tel', $v('telefono'), 'inputmode="numeric" maxlength="9"', '9 dígitos, empieza con 9') ?></div>
            <div class="col-md-6"><?= p_campo('email', 'Correo <span class="op">(opcional)</span>', 'email', $v('email')) ?></div>
            <div class="col-md-8"><?= p_campo('direccion', 'Dirección <span class="op">(opcional)</span>', 'text', $v('direccion'), '', 'Sin dirección, la ficha se marca como incompleta') ?></div>
            <div class="col-md-4"><?= p_campo('distrito', 'Distrito <span class="op">(opcional)</span>', 'text', $v('distrito')) ?></div>
          </div>
        </fieldset>
        <fieldset class="paso" data-paso="3" hidden><legend class="visually-hidden">Datos laborales</legend>
          <div class="row">
            <div class="col-md-6"><?= $sel('empresaId', 'Empresa', opciones($activosDe('empresas'), $emp0, fn($x) => $x['razon'], 'Seleccione'), $req.' data-filtra="#p-sedeId"') ?></div>
            <div class="col-md-6"><?= $sel('sedeId', 'Sede', $sedesOps, $req) ?></div>
            <div class="col-md-6"><?= $sel('areaId', 'Área', opciones($activosDe('areas'), $v('areaId'), fn($x) => $x['nombre'], 'Seleccione'), $req.' data-filtra="#p-puestoId"') ?></div>
            <div class="col-md-6"><?= $sel('puestoId', 'Puesto', $puestosOps, $req) ?></div>
            <div class="col-md-6"><?= $sel('jefeId', 'Jefe directo <span class="op">(opcional)</span>', opciones($jefes, $v('jefeId'), 'nom', 'Sin jefe directo')) ?></div>
            <div class="col-md-6"><?= $sel('horarioId', 'Horario', opciones($activosDe('horarios'), $v('horarioId'), fn($x) => $x['nombre'].' ('.$x['entrada'].' a '.$x['salida'].')', 'Seleccione'), $req) ?></div>
            <div class="col-md-6"><?= $sel('ccId', 'Centro de costo <span class="op">(opcional)</span>', opciones($activosDe('ccs'), $v('ccId'), fn($x) => $x['codigo'].' · '.$x['nombre'], 'Sin asignar')) ?></div>
          </div>
          <?php if ($id): ?>
          <div class="contexto mt-1"><?= ic('info') ?> Ingreso: <b><?= f($c['fecIngreso']) ?></b> · Contrato: <b><?= e($c['tipoContrato']) ?><?= $c['finContrato'] ? ' hasta el '.f($c['finContrato']) : '' ?></b>. Para cambiar el sueldo, el contrato o el puesto con fecha efectiva use <a href="<?= url('colaborador', ['id' => $id]) ?>">Registrar movimiento</a> en la ficha, así queda en el historial.</div>
          <?php else: ?>
          <hr class="my-3">
          <div class="row">
            <div class="col-md-4"><?= p_fecha('fecIngreso', 'Fecha de ingreso', HOY) ?></div>
            <div class="col-md-4"><?= $sel('tipoContrato', 'Tipo de contrato', opciones_txt(['Plazo fijo', 'Indeterminado', 'Tiempo parcial'], 'Plazo fijo')) ?></div>
            <div class="col-md-4" data-si="#p-tipoContrato=Plazo fijo|Tiempo parcial"><?= p_fecha('finContrato', 'Fin del contrato', add_dias(add_meses(HOY, 6), -1)) ?></div>
            <?php if (puede('personal.sueldo')): ?><div class="col-md-4"><?= p_campo('sueldo', 'Sueldo mensual (S/)', 'text', '', $req.' inputmode="decimal" placeholder="0.00"') ?></div><?php endif; ?>
          </div>
          <?php endif; ?>
        </fieldset>
        <fieldset class="paso" data-paso="4" hidden><legend class="visually-hidden">Pensión y banco</legend>
          <div class="row">
            <div class="col-md-6"><?= $sel('pension', 'Sistema de pensiones', opciones_txt(['ONP', 'AFP Integra', 'AFP Prima', 'AFP Profuturo', 'AFP Habitat'], $v('pension', 'ONP'))) ?></div>
            <div class="col-md-6" data-si="#p-pension=AFP Integra|AFP Prima|AFP Profuturo|AFP Habitat"><?= p_campo('cuspp', 'CUSPP <span class="op">(opcional)</span>', 'text', $v('cuspp'), 'maxlength="12" style="text-transform:uppercase"', '12 caracteres') ?></div>
            <div class="col-md-6"><?= $sel('banco', 'Banco de la cuenta de sueldo', opciones_txt(['BCP', 'BBVA', 'Interbank', 'Scotiabank', 'Banco de la Nación'], $v('banco', 'BCP'))) ?></div>
            <div class="col-md-6"><?= p_campo('cuenta', 'Número de cuenta <span class="op">(opcional)</span>', 'text', $v('cuenta'), 'inputmode="numeric" maxlength="20"') ?></div>
          </div>
          <p class="sub2 mb-0"><?= ic('lock-simple') ?> La cuenta y el CUSPP solo los ven los perfiles autorizados; cada consulta queda en la auditoría.</p>
        </fieldset>
      </div>
      <div class="card-footer d-flex gap-2">
        <a class="btn btn-ter" href="<?= $id ? url('colaborador', ['id' => $id]) : url('personal') ?>">Cancelar</a>
        <button type="button" class="btn btn-sec ms-auto" data-paso="ant"><?= ic('arrow-left') ?> Anterior</button>
        <button type="button" class="btn btn-primary" data-paso="sig" data-no-ultimo>Siguiente <?= ic('arrow-right') ?></button>
        <button class="btn btn-primary" data-solo-ultimo hidden><?= $id ? 'Guardar cambios' : 'Registrar colaborador' ?></button>
      </div>
    </form>
  </div>
  <div class="col-xl-4">
    <section class="bloque-plano">
      <div class="cab"><h2>Foto del colaborador</h2></div>
      <div class="d-flex gap-3 align-items-center mb-3">
        <?= $id ? avatar($c, 'xl') : '<span class="av av-xl" style="--av-bg:#E4E9EE;--av-fg:#55616E" aria-hidden="true"><span class="ini">'.ic('user').'</span></span>' ?>
        <p class="sub2 mb-0">Mientras no haya foto se muestran las iniciales sobre un color fijo para cada persona.</p>
      </div>
      <p class="small mb-1">Para agregarla, copie la imagen (400 × 400 px, JPG) en:</p>
      <code class="d-block small mb-3">assets/img/<?= e($foto ?: 'personas/nombre-apellido.jpg') ?></code>
      <?php if ($id): ?>
      <div class="cab mt-4"><h2>Antes de guardar</h2></div>
      <ul class="sub2 ps-3 mb-0"><li>Los cambios de datos personales y de contacto no se registran en el historial.</li><li>La edición queda en la auditoría con su usuario.</li></ul>
      <?php else: ?>
      <div class="cab mt-4"><h2>Después de registrar</h2></div>
      <ul class="sub2 ps-3 mb-0"><li>Se le asigna el siguiente código disponible.</li><li>Su historial empieza con el ingreso.</li><li>Puede crearle un usuario en Configuración para que vea su ficha y pida vacaciones.</li></ul>
      <?php endif; ?>
    </section>
  </div>
</div>
