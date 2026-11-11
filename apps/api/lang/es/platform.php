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
];
