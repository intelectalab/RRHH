<?php
/**
 * index.php — Punto de entrada único.
 *  - index.php?p=<pantalla>         muestra una pantalla de /pages
 *  - index.php?accion=<acción>      procesa un formulario (includes/acciones.php)
 *  - index.php?panel=<panel>        devuelve un panel lateral o ventana (includes/paneles.php)
 *  - index.php?api=buscar&q=…       búsqueda rápida de colaboradores (JSON)
 *  - index.php?exportar=<lista>     descarga un CSV para Excel
 *  - index.php?descargar=<id>       descarga segura de documentos adjuntos
 *  - index.php?imprimir=constancia  emisión imprimible de constancia de trabajo
 */

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require __DIR__.'/includes/config.php';
require __DIR__.'/includes/funciones.php';
require __DIR__.'/includes/datos.php';
require __DIR__.'/includes/reglas.php';
require __DIR__.'/includes/componentes.php';
require __DIR__.'/includes/graficos.php';
require __DIR__.'/includes/acciones.php';
require __DIR__.'/includes/paneles.php';
require __DIR__.'/includes/exportar.php';
require __DIR__.'/includes/filtros.php';
require __DIR__.'/includes/correo.php';

// Control de expiración e inactividad de sesión
if (!empty($_SESSION['uid'])) {
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > 1800)) {
        unset($_SESSION['uid'], $_SESSION['last_activity'], $_SESSION['login_time'], $_SESSION['recordar']);
        $_SESSION['error_login'] = 'Su sesión se cerró por inactividad (30 minutos).';
    } else {
        $_SESSION['last_activity'] = $now;
    }
    if (!empty($_SESSION['recordar']) && isset($_SESSION['login_time']) && ($now - $_SESSION['login_time'] > 28800)) {
        unset($_SESSION['uid'], $_SESSION['last_activity'], $_SESSION['login_time'], $_SESSION['recordar']);
        $_SESSION['error_login'] = 'Su sesión ha expirado (8 horas).';
    }
}

// La sesión deja de ser válida si el usuario fue desactivado
if (!empty($_SESSION['uid']) && !(usuario()['activo'] ?? 0)) { unset($_SESSION['uid']); }

if (isset($_GET['accion'])) ejecutar_accion((string)$_GET['accion']);

if (!usuario()) {                                   // Sin sesión: solo la pantalla de ingreso
    if (isset($_GET['panel']) || isset($_GET['api'])) { http_response_code(401); exit; }
    $p = 'login'; $pagina = ['sin_marco' => true];
    ob_start(); include __DIR__.'/pages/login.php'; $contenido = ob_get_clean();
    include __DIR__.'/includes/cabecera.php'; echo $contenido; include __DIR__.'/includes/pie.php';
    exit;
}

// Si debe_cambiar = 1, después de ingresar redirige obligatoriamente a «Cambiar contraseña»
if (!empty(usuario()['debe_cambiar'])) {
    $p = 'cambiar_clave'; $pagina = ['sin_marco' => true, 'titulo' => 'Cambiar contraseña'];
    ob_start(); include __DIR__.'/pages/cambiar_clave.php'; $contenido = ob_get_clean();
    include __DIR__.'/includes/cabecera.php'; echo $contenido; include __DIR__.'/includes/pie.php';
    exit;
}

if (isset($_GET['descargar'])) { accion_descargar_documento((int)$_GET['descargar']); exit; }
if (isset($_GET['imprimir'])) { accion_imprimir((string)$_GET['imprimir'], (int)($_GET['id'] ?? 0)); exit; }
if (isset($_GET['panel'])) { echo render_panel((string)$_GET['panel']); exit; }
if (($_GET['api'] ?? '') === 'buscar') { api_buscar((string)($_GET['q'] ?? '')); exit; }
if (isset($_GET['exportar'])) { exportar((string)$_GET['exportar']); exit; }

/* Pantallas disponibles: clave => [archivo en /pages, permiso requerido o null] */
$PAGINAS = [
    'inicio'                => ['inicio.php', null],
    'personal'              => ['personal.php', 'personal.ver'],
    'colaborador'           => ['colaborador.php', null],          // valida el alcance dentro de la página
    'mi_ficha'              => ['colaborador.php', null],
    'colaborador_form'      => ['colaborador_form.php', 'personal.editar'],
    'organigrama'           => ['organigrama.php', 'personal.ver'],
    'contratos'             => ['contratos.php', 'personal.ver'],
    'cumpleanos'            => ['cumpleanos.php', 'personal.ver'],
    'importar'              => ['importar.php', 'personal.editar'],
    'asistencia'            => ['asistencia.php', 'asistencia.ver'],
    'importar_marcaciones'  => ['importar_marcaciones.php', 'asistencia.editar'],
    'asistencia_mensual'    => ['asistencia_mensual.php', 'asistencia.ver'],
    'horarios'              => ['organizacion.php', 'asistencia.ver'],
    'mis_marcaciones'       => ['colaborador.php', null],
    'vacaciones'            => ['vacaciones.php', 'vacaciones.ver'],
    'vacaciones_calendario' => ['vacaciones_calendario.php', 'vacaciones.ver'],
    'vacaciones_saldos'     => ['vacaciones_saldos.php', 'vacaciones.ver'],
    'mis_vacaciones'        => ['mis_vacaciones.php', null],
    'aprobaciones'          => ['aprobaciones.php', 'vacaciones.aprobar'],
    'organizacion'          => ['organizacion.php', 'organizacion.editar'],
    'configuracion'         => ['usuarios.php', 'config.usuarios'],
    'usuarios'              => ['usuarios.php', 'config.usuarios'],
    'perfiles'              => ['perfiles.php', 'config.usuarios'],
    'parametros'            => ['parametros.php', 'config.usuarios'],
    'plan'                  => ['plan.php', 'config.usuarios'],
    'auditoria'             => ['auditoria.php', 'config.auditoria'],
    'cambiar_clave'         => ['cambiar_clave.php', null],
];
$p = (string)($_GET['p'] ?? 'inicio');
if ($p === 'configuracion' && !puede('config.usuarios') && puede('config.auditoria')) $p = 'auditoria';
if (!isset($PAGINAS[$p])) $p = 'inicio';
[$archivo, $permiso] = $PAGINAS[$p];

$pagina = ['titulo' => ''];
ob_start();
if ($permiso && !puede($permiso)) include __DIR__.'/pages/sin_acceso.php';
else include __DIR__.'/pages/'.$archivo;
$contenido = ob_get_clean();

include __DIR__.'/includes/cabecera.php';
echo $contenido;
include __DIR__.'/includes/pie.php';
