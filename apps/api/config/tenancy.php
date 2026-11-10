<?php

return [
    /*
     * Dominio base de la plataforma. La organización se resuelve por el subdominio
     * `{slug}.{base_domain}` o por un dominio propio verificado (tenant_domains).
     * Ver docs/02-arquitectura.md §5.1.
     */
    'base_domain' => env('TENANCY_BASE_DOMAIN', 'localhost'),

    /*
     * Dirección de la aplicación web de cada organización, para los enlaces de correos y avisos.
     * {slug} se reemplaza por el subdominio de la organización.
     */
    'app_url' => env('TENANCY_APP_URL', 'http://{slug}.localhost:3000'),

    /*
     * Subdominios reservados que nunca resuelven a una organización
     * (consola de plataforma, sitio público, etc.).
     */
    'reserved_subdomains' => ['admin', 'www', 'api', 'app', 'static', 'mail'],

    /*
     * Estados de organización que pueden operar. El resto responde 403 tenant_suspended
     * (suspended, blocked) o 404 (cancelled, deleted).
     */
    'operable_statuses' => ['trial', 'active', 'grace_period'],
    'suspended_statuses' => ['suspended', 'blocked'],
];
