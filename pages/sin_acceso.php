<?php
/** sin_acceso.php — Se muestra cuando el perfil no tiene permiso para la pantalla pedida. */
$pagina['titulo'] = 'Sin acceso';
echo '<div class="card">'.estado_vacio('Su perfil no tiene acceso a esta sección', 'Solicite el acceso al administrador de su empresa.', '<a class="btn btn-sec btn-sm" href="'.url('inicio').'">Volver a Inicio</a>').'</div>';
