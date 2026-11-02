<?php
/**
 * config.local.example.php — Plantilla de configuración local y credenciales del entorno.
 * Copie este archivo como config.local.php y configure los valores correspondientes.
 */

// Base de datos MySQL / MariaDB
define('DB_HOST', 'localhost');
define('DB_NOMBRE', 'aynihr');
define('DB_USUARIO', 'root');
define('DB_CLAVE', '');
define('DB_PUERTO', 3306);

// Servidor de correo saliente SMTP (opcional para notificaciones)
define('SMTP_HOST', '');
define('SMTP_PUERTO', 587);
define('SMTP_USUARIO', '');
define('SMTP_CLAVE', '');
define('SMTP_SEGURIDAD', 'tls'); // 'tls', 'ssl' o ''
define('SMTP_DE_EMAIL', '');
define('SMTP_DE_NOMBRE', 'AyniHR');

// Clave secreta para cifrado de datos sensibles (32 caracteres o binario en hex)
define('APP_CLAVE', 'aynihr_clave_secreta_para_cifrado_aes256_gcm_2026!');

