<?php

/*
 * SEG-11 · Cifrado de campos sensibles con claves fuera de la base (kit/docs/12 §4).
 *
 * FIELD_ENCRYPTION_KEYS: anillo de claves «id:base64:clave» separadas por coma (32 bytes cada una).
 * FIELD_ENCRYPTION_ACTIVE_KEY: id de la clave con la que se cifra lo nuevo.
 * Sin anillo configurado se usa una clave derivada de APP_KEY (id «app»); en producción las claves
 * viven en el gestor de secretos y nunca en la base ni en el repositorio.
 * Procedimiento de rotación: docs/seguridad-cifrado-de-campos.md.
 */
return [
    'field_keys' => env('FIELD_ENCRYPTION_KEYS', ''),
    'field_active_key' => env('FIELD_ENCRYPTION_ACTIVE_KEY'),

    /*
     * Columnas cifradas por tabla, que recorre `security:rotate-field-keys`.
     * Solo tablas de organización (con tenant_id y RLS). La auditoría no se reescribe: es de solo
     * inserción (regla 5) y sus valores sensibles se cifran con APP_KEY (ver el documento).
     */
    'encrypted_columns' => [
        'user_identities' => ['identifier_enc'],
        'user_mfa_factors' => ['secret_enc'],
        'notification_deliveries' => ['recipient_address_enc'],
    ],

    /*
     * SEG-12 · Exportaciones: filas máximas por archivo y días que el archivo queda disponible.
     * Son límites técnicos de la plataforma, no reglas legales.
     */
    'export_max_rows' => (int) env('EXPORT_MAX_ROWS', 50000),
    'export_ttl_days' => (int) env('EXPORT_TTL_DAYS', 7),
];
