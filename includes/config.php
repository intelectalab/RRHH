<?php
/**
 * config.php — Configuración general del sistema.
 * Zona horaria del negocio (Lima), rutas y constantes. Se carga primero desde index.php.
 */

date_default_timezone_set('America/Lima');          // La fecha del negocio es la de Lima, no la del servidor.
mb_internal_encoding('UTF-8');

// ── Errores: nunca mostrar al cliente; siempre registrar en el log ──────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
// El log se define después de RAIZ; se redefine más abajo si es necesario.

define('APP_NOMBRE', 'AyniHR');
define('APP_VERSION', '1.0');
define('RAIZ', dirname(__DIR__));                    // C:\xampp\htdocs\aynihr
define('DIR_DATOS', RAIZ . '/data');                 // Carpeta de datos del sistema
define('DIR_ARCHIVOS', RAIZ . '/data/archivos');     // Almacenamiento seguro de documentos
define('DIR_IMG', RAIZ . '/assets/img');             // Fotos de personas, sedes, empresas y portada
define('URL_IMG', 'assets/img');                     // Ruta pública de las imágenes (relativa a index.php)
define('HOY', date('Y-m-d'));

// Log de errores PHP en la carpeta protegida data/logs/
ini_set('error_log', RAIZ . '/data/logs/php_error.log');

// Configuración local del entorno (credenciales MySQL, SMTP, claves)
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    require_once __DIR__ . '/config.local.example.php';
}
