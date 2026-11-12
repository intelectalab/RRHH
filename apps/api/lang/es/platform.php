<?php

return [
    'invalid_credentials' => 'El correo o la clave no son correctos, o la cuenta está bloqueada temporalmente.',
    'invalid_code' => 'El código no es válido o ya se usó.',
    'clock_skew' => 'El código corresponde a otra hora: el reloj de tu teléfono o del servidor está desfasado unos :minutes minutos.',
    'mfa_already_enabled' => 'La verificación en dos pasos ya está configurada. Para cambiarla, pide que la restablezcan.',
    'mfa_enrollment_not_started' => 'Primero inicia la configuración de la aplicación autenticadora.',
    'mfa_not_enabled' => 'La verificación en dos pasos aún no está configurada.',
    'roles' => [
        'superadmin' => 'Propietario',
        'support' => 'Soporte',
        'billing' => 'Finanzas',
        'readonly' => 'Operaciones (solo lectura)',
    ],
    'tenants' => [
        'slug_taken' => 'Ese subdominio ya está en uso o está reservado.',
        'reason_required' => 'Indica el motivo: queda en el historial y en el aviso a la organización.',
        'retention_expired' => 'Venció el periodo de retención de la organización: ya no se puede reactivar.',
        'unknown_country' => 'El país no existe en el catálogo.',
        'unknown_currency' => 'La moneda no existe en el catálogo.',
        'unknown_timezone' => 'La zona horaria no es válida (por ejemplo, America/Lima).',
        'unknown_regime' => 'El régimen laboral no corresponde al país.',
    ],
    'modules' => [
        'core' => 'Los módulos base no se retiran.',
        'required_by' => 'Otros módulos activos dependen de este: :modules. Retíralos primero.',
    ],
];
