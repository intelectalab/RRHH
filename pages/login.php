<?php
/**
 * login.php — Pantalla de ingreso: presentación editorial y formulario.
 */
$pagina['titulo'] = 'Ingresar';
$error = $_SESSION['error_login'] ?? ''; unset($_SESSION['error_login']);
?>
<div class="login">
  <section class="login-editorial" aria-label="AyniHR, gestión de personal">
    <div class="login-brand"><?php include RAIZ.'/assets/img/logo-aynihr.svg'; ?><span>Ayni<span class="brand-light">HR</span></span></div>
    <div class="login-story"><span class="eyebrow">GESTIÓN DE PERSONAL</span>
      <h2>El trabajo de cada día.<br><em>Las personas, primero.</em></h2>
      <p>Un espacio para consultar la asistencia, organizar las vacaciones y mantener al día la información de tu equipo.</p>
    </div>
    <div class="login-index"><div><span>01</span> Personas y equipos</div><div><span>02</span> Asistencia y horarios</div><div><span>03</span> Vacaciones y permisos</div></div>
    <div class="login-colophon"><span>AyniHR</span><span>Tu espacio de trabajo</span></div>
  </section>
  <div class="login-form">
    <div class="caja">
      <div class="logo mb-4"><?php include RAIZ.'/assets/img/logo-aynihr.svg'; ?><span><b>Ayni</b><span>HR</span></span></div>
      <span class="eyebrow">ACCESO A LA PLATAFORMA</span><h1>Buen día.</h1>
      <p class="text-secondary mb-4">Ingresa con la cuenta que te asignó tu empresa.</p>
      <form method="post" action="index.php?accion=login" novalidate>
        <?= campo_csrf() ?>
        <label class="form-label" for="l-u">Usuario</label>
        <input type="text" class="form-control mb-3" id="l-u" name="usuario" autocomplete="username" placeholder="Ingrese su usuario" required autofocus>
        <div class="d-flex justify-content-between"><label class="form-label" for="l-p">Contraseña</label><a href="#" class="small" data-aviso="Para restablecer su contraseña, comuníquese con el área de RR. HH. de su empresa.">¿Olvidó su contraseña?</a></div>
        <div class="input-group mb-2">
          <input type="password" class="form-control <?= $error ? 'is-invalid' : '' ?>" id="l-p" name="clave" value="" autocomplete="current-password" placeholder="Ingrese su contraseña" required>
          <button type="button" class="btn btn-sec" data-ver-clave="#l-p" aria-label="Mostrar u ocultar la contraseña"><?= ic('eye') ?></button>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="rec" name="recordar" value="1" checked>
          <label class="form-check-label small" for="rec">Mantener la sesión en este equipo</label>
        </div>
        <?php if ($error): ?><div class="invalid-feedback d-block mb-2" role="alert"><?= e($error) ?></div><?php endif; ?>
        <button class="btn btn-primary w-100 btn-grande">Ingresar</button>
      </form>
      <p class="small text-secondary mt-4 mb-0">¿Problemas para ingresar? Comuníquese con el área de RR. HH. de su empresa.</p>
    </div>
  </div>
</div>
