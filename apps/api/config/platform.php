<?php

/*
 * Consola del propietario del SaaS (M-SAS, SAS-1). Valores técnicos de seguridad de la
 * consola, no parámetros legales: la consola no pertenece a ninguna organización, así que
 * no usa la tabla de parámetros por organización.
 */
return [
    // Cookie propia: una sesión de organización nunca abre la consola, ni al revés.
    'session_cookie' => env('PLATFORM_SESSION_COOKIE', 'gestia_console_session'),
    'xsrf_cookie' => 'CONSOLE-XSRF-TOKEN',
    // Más corta que la de las organizaciones: la consola opera entre organizaciones.
    'session_lifetime_minutes' => (int) env('PLATFORM_SESSION_LIFETIME', 240),
    'idle_minutes' => (int) env('PLATFORM_SESSION_IDLE', 30),
    // Bloqueo por intentos fallidos (clave o código del segundo factor).
    'max_failed_attempts' => 5,
    'lockout_minutes' => 30,
    'login_attempts_per_minute' => 10,
    // Nombre que muestra la aplicación autenticadora.
    'mfa_issuer' => env('PLATFORM_MFA_ISSUER', 'GestiaHR Consola'),
    'password_min_length' => 14,

    /*
     * Roles internos de la consola (M-SAS §5: propietario, soporte, finanzas, operaciones)
     * sobre el enum de platform_users.role. Cada rol trae permisos `platform.*` del catálogo;
     * '*' = todos. Los usuarios de las organizaciones no tienen ninguno.
     */
    /*
     * SAS-2 · SAS-5 · Valores por defecto de la configuración global (tabla platform_settings; la fila,
     * si existe, manda). Todos son «por validar» con el área legal y comercial.
     */
    'settings' => [
        // RN-SAS-10: días que se conservan los datos tras la baja, por país («default» para el resto).
        'tenants.retention_days_after_cancel' => ['default' => 90],
        // RN-SAS-06: días que un módulo retirado queda en solo lectura antes de ocultarse.
        'modules.read_only_transition_days' => 30,
        // SAS-4 · RN-SAS-04: días de gracia (organización en solo lectura) tras vencer la prueba sin conversión.
        'subscriptions.trial_grace_days' => 7,
    ],

    'roles' => [
        'superadmin' => ['*'],
        'support' => [
            'platform.tenants.view', 'platform.support_sessions.view', 'platform.support_sessions.create',
            'platform.support_sessions.end', 'platform.health.view', 'platform.metrics.view',
            'platform.help_articles.view', 'platform.web_complaints.view',
        ],
        'billing' => [
            'platform.tenants.view', 'platform.plans.view', 'platform.subscriptions.view',
            'platform.subscriptions.update', 'platform.subscriptions.change_plan', 'platform.subscriptions.suspend',
            'platform.subscriptions.reactivate', 'platform.metrics.view',
        ],
        'readonly' => [
            'platform.tenants.view', 'platform.plans.view', 'platform.subscriptions.view',
            'platform.health.view', 'platform.metrics.view',
        ],
    ],
];
