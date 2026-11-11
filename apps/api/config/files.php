<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Parámetros de servicio de archivos (RN-PLT-07, RN-PLT-10)
    |--------------------------------------------------------------------------
    */

    // Disco de almacenamiento (s3 / local en pruebas)
    'disk' => env('FILESYSTEM_DISK', 's3'),

    // Tamaño máximo por archivo en bytes (por defecto 25 MB)
    'max_size_bytes' => (int) env('FILES_MAX_SIZE_BYTES', 25 * 1024 * 1024),

    // Tipos MIME permitidos
    'allowed_mimes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/svg+xml',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',  // xlsx
        'text/csv',
        'text/plain',
        'application/zip',
    ],

    // CFG-2 · Tamaño máximo del logo de una empresa (1 MB)
    'logo_max_bytes' => (int) env('FILES_LOGO_MAX_BYTES', 1024 * 1024),

    // Duración de la URL firmada en segundos (por defecto 5 minutos / 300 s)
    'signed_url_ttl_seconds' => (int) env('FILES_SIGNED_URL_TTL', 300),

    // Estado de antivirus por defecto al subir
    'scan_status_default' => env('FILES_VIRUS_SCAN_STATUS', 'clean'),
];
