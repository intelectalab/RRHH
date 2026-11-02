<?php
/**
 * cambiar_clave.php — Cambio obligatorio u opcional de contraseña.
 * Mismo diseño editorial y caja de presentación que la pantalla de ingreso.
 */
$pagina['titulo'] = 'Cambiar contraseña';
$u = usuario();
$error = $_SESSION['error_cambiar_clave'] ?? ''; unset($_SESSION['error_cambiar_clave']);
$debe = !empty($u['debe_cambiar']);
?>
<div class="login">
  <section class="login-editorial" aria-label="AyniHR, gestión de personal">
    <div class="login-brand"><?php include RAIZ.'/assets/img/logo-aynihr.svg'; ?><span>Ayni<span class="brand-light">HR</span></span></div>
    <div class="login-story"><span class="eyebrow">SEGURIDAD DE LA CUENTA</span>
      <h2>Protección de sus datos.<br><em>Su clave es personal.</em></h2>
      <p><?= $debe ? 'Por seguridad de la empresa y de su información, es obligatorio definir una nueva contraseña personal antes de continuar.' : 'Mantenga su cuenta segura actualizando su contraseña periódicamente.' ?></p>
    </div>
    <div class="login-index">
      <div><span>01</span> Mínimo 10 caracteres</div>
      <div><span>02</span> Combinación de letras y números</div>
      <div><span>03</span> Distinta de la contraseña anterior</div>
    </div>
    <div class="login-colophon"><span>AyniHR</span><span>Seguridad de la información</span></div>
  </section>
  <div class="login-form">
    <div class="caja">
      <div class="logo mb-4"><?php include RAIZ.'/assets/img/logo-aynihr.svg'; ?><span><b>Ayni</b><span>HR</span></span></div>
      <span class="eyebrow">ACTUALIZACIÓN DE CLAVE</span>
      <h1>Cambiar contraseña</h1>
      <p class="text-secondary mb-4"><?= $debe ? 'Debe asignar una contraseña definitiva para comenzar a usar el sistema.' : 'Ingrese su clave actual y la nueva que desea utilizar.' ?></p>

      <form method="post" action="index.php?accion=cambiar_clave" novalidate>
        <?= campo_csrf() ?>

        <label class="form-label" for="c-act">Contraseña actual</label>
        <div class="input-group mb-3">
          <input type="password" class="form-control" id="c-act" name="actual" required autocomplete="current-password" autofocus>
          <button type="button" class="btn btn-sec" data-ver-clave="#c-act" aria-label="Mostrar u ocultar la contraseña"><?= ic('eye') ?></button>
        </div>

        <label class="form-label" for="c-nueva">Nueva contraseña</label>
        <div class="input-group mb-1">
          <input type="password" class="form-control" id="c-nueva" name="nueva" required autocomplete="new-password">
          <button type="button" class="btn btn-sec" data-ver-clave="#c-nueva" aria-label="Mostrar u ocultar la contraseña"><?= ic('eye') ?></button>
        </div>
        <div class="form-text text-secondary mb-3">Mínimo 10 caracteres con letras y números; no puede ser igual a la anterior.</div>

        <label class="form-label" for="c-conf">Confirmar nueva contraseña</label>
        <div class="input-group mb-3">
          <input type="password" class="form-control" id="c-conf" name="confirmar" required autocomplete="new-password">
          <button type="button" class="btn btn-sec" data-ver-clave="#c-conf" aria-label="Mostrar u ocultar la contraseña"><?= ic('eye') ?></button>
        </div>

        <?php if ($error): ?><div class="invalid-feedback d-block mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>

        <button class="btn btn-primary w-100 btn-grande mb-3">Actualizar contraseña</button>
      </form>

      <?php if (!$debe): ?>
        <div class="text-center">
          <a href="<?= url('inicio') ?>" class="small text-secondary"><?= ic('arrow-left') ?> Volver al inicio</a>
        </div>
      <?php else: ?>
        <form method="post" action="index.php?accion=salir" class="text-center mt-2">
          <?= campo_csrf() ?>
          <button class="btn btn-link btn-sm text-secondary p-0"><?= ic('sign-out') ?> Cerrar sesión y salir</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

