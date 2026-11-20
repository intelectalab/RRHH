<?php

/*
 * Identidad y sesiones (M-SEG). Son valores técnicos de seguridad por defecto,
 * no parámetros legales; las políticas por organización (SEG-4, SEG-5) los
 * acotarán dentro de estos márgenes.
 */
return [
    'session_cookie' => env('IDENTITY_SESSION_COOKIE', 'gestia_session'),
    'xsrf_cookie' => 'XSRF-TOKEN',
    // ESS-6 · Cookie HttpOnly del reingreso rápido con PIN en el teléfono.
    'device_cookie' => env('IDENTITY_DEVICE_COOKIE', 'gestia_device'),
    'session_lifetime_minutes' => (int) env('IDENTITY_SESSION_LIFETIME', 480),
    'password_reset_ttl_minutes' => (int) env('IDENTITY_PASSWORD_RESET_TTL', 30),
    'invitation_ttl_hours' => (int) env('IDENTITY_INVITATION_TTL', 72),
    'password_min_length' => 12,
    'login_attempts_per_minute' => 10,
    // Márgenes de la plataforma para el límite general de peticiones (SEG-5).
    'api_requests_per_minute_user' => (int) env('IDENTITY_API_RATE_USER', 600),
    'api_requests_per_minute_ip' => (int) env('IDENTITY_API_RATE_IP', 300),
    'secure_cookies' => (bool) env('IDENTITY_SECURE_COOKIES', env('APP_ENV') === 'production'),
];
