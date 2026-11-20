<?php

/*
 * Consola del propietario del SaaS (M-SAS, SAS-1). Valores técnicos de seguridad de la
 * consola, no parámetros legales: la consola no pertenece a ninguna organización, así que
 * no usa la tabla de parámetros por organización.
 */
return [
    // WEB-5 a WEB-7 · Dirección del sitio comercial para los enlaces de los correos (demo, lista de espera).
    'site_url' => env('SITE_URL', 'http://localhost:3001'),
    'public_form_attempts_per_minute' => (int) env('PUBLIC_FORM_ATTEMPTS_PER_MINUTE', 20),
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
    // SAS-13 · Archivo del log técnico estructurado (JSON por línea) que la consola consulta; vacío = no disponible.
    'technical_log_path' => env('PLATFORM_TECHNICAL_LOG', storage_path('logs/structured.log')),
    // SAS-9 · Colas que muestra el monitoreo además de «default».
    'monitored_queues' => ['notifications', 'imports', 'exports'],

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
        // SAS-13 · Países en los que se pueden crear organizaciones (paquete de país disponible).
        'tenants.enabled_countries' => ['PE'],
        // SAS-13 · Datos con que nace una organización de cada país si el alta no los indica.
        'tenants.defaults' => ['PE' => ['currency' => 'PEN', 'timezone' => 'America/Lima', 'locale' => 'es']],
        // SAS-13 · Parámetros de política con que nace toda organización nueva (código => valor); vacío = los del catálogo.
        'tenants.default_parameters' => [],
        // SAS-8 · RN-SAS-08: duración máxima de una sesión de soporte (minutos).
        'support.max_session_minutes' => 60,
        // SAS-12 · Días que se conserva cada copia y cada cuántos días se prueba una restauración en aislado.
        'backups.retention_days' => 30,
        'backups.test_restore_every_days' => 7,
        // SAS-10 · Moneda en que el tablero consolida los ingresos y tipos de cambio que registra la consola (moneda => unidades
        // de la moneda de reporte); una moneda sin tipo se informa aparte, nunca se convierte con un valor inventado.
        'dashboard.report_currency' => 'PEN',
        'dashboard.exchange_rates' => [],
        // SAS-11 · ADR 0010 · Cobro de la suscripción: días para pagar desde la emisión, aviso previo al vencimiento,
        // días de mora tras los que se suspende la organización (0 = no se suspende sola), serie del comprobante electrónico
        // y tratamiento del impuesto para organizaciones fuera del Perú («pending» hasta que lo confirme el contador:
        // mientras tanto no se emite a esas organizaciones).
        'billing.due_days' => 15,
        'billing.reminder_days_before_due' => 3,
        'billing.suspend_after_overdue_days' => 7,
        'billing.einvoice_series' => 'F001',
        'billing.foreign_tax_treatment' => 'pending',
        // WEB-5 · Días de prueba de un registro desde el sitio cuando el plan no define los suyos («por validar» con ventas).
        'web.signup_trial_days' => 14,
        // WEB-5 · RN-WEB-07: días que se guarda un registro a medias (como lead) antes de borrarlo.
        'web.abandoned_signup_retention_days' => 90,
        // WEB-6 · Demos: duración, anticipación mínima, días ofrecidos, horario (hora de Lima) y días de la semana (1 = lunes).
        'web.demo_slot_minutes' => 30,
        'web.demo_min_notice_hours' => 24,
        'web.demo_days_ahead' => 14,
        'web.demo_day_start' => '09:00',
        'web.demo_day_end' => '18:00',
        'web.demo_timezone' => 'America/Lima',
        'web.demo_weekdays' => [1, 2, 3, 4, 5],
        // WEB-7 · Cupón de lanzamiento por módulo que va en el aviso de la lista de espera (código del módulo => cupón).
        'web.launch_coupons' => [],
    ],

    'roles' => [
        'superadmin' => ['*'],
        'support' => [
            'platform.tenants.view', 'platform.support_sessions.view', 'platform.support_sessions.create',
            'platform.support_sessions.end', 'platform.health.view', 'platform.metrics.view',
            'platform.help_articles.view', 'platform.web_complaints.view', 'platform.logs.view',
        ],
        'billing' => [
            'platform.tenants.view', 'platform.plans.view', 'platform.subscriptions.view',
            'platform.subscriptions.update', 'platform.subscriptions.change_plan', 'platform.subscriptions.suspend',
            'platform.subscriptions.reactivate', 'platform.metrics.view', 'platform.billing.view', 'platform.billing.manage',
        ],
        'readonly' => [
            'platform.tenants.view', 'platform.plans.view', 'platform.subscriptions.view',
            'platform.health.view', 'platform.metrics.view', 'platform.billing.view',
        ],
    ],
];
