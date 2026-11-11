<?php

return [
    'password_reset' => [
        'subject' => 'Recupere su clave de Ayni HR',
        'body' => "Recibimos una solicitud para restablecer su clave.\n\nUse este enlace (válido por :minutes minutos, una sola vez):\n:link\n\nSi usted no lo pidió, ignore este mensaje.",
        'requested' => 'Si el correo pertenece a una cuenta activa, enviaremos un enlace para restablecer la clave.',
    ],
    'roles' => [
        'copy_of' => 'Copia de :name',
        'system_immutable' => 'Los roles de sistema no se editan ni se eliminan; duplíquelos para ajustarlos.',
        'unknown_permission' => 'El permiso :code no existe en el catálogo o no es de este tipo.',
        'cannot_grant' => 'No puede conceder permisos que usted no tiene: :codes.',
        'name_taken' => 'Ya existe un rol activo con ese nombre; cámbiele el nombre antes de restaurar este.',
    ],
    'users' => [
        'scope_units_required' => 'Indique al menos una empresa, sede o unidad para ese alcance.',
        'unknown_scope_unit' => 'Una de las empresas, sedes o unidades no existe en esta organización.',
        'invite_without_email' => 'El usuario no tiene correo: entréguele un código de activación desde RR. HH.',
        'reset_without_email' => 'El usuario no tiene correo para recibir el enlace.',
        'unknown_role' => 'Uno o más roles no existen en esta organización.',
        'last_admin' => 'La organización no puede quedar sin un Administrador empresa activo.',
    ],
    'invitation' => [
        'subject' => 'Active su cuenta de Ayni HR',
        'body' => "Su empresa le creó una cuenta en Ayni HR.\n\nActívela y defina su contraseña con este enlace (válido por :hours horas):\n:link",
    ],
    'password' => [
        'too_short' => 'La clave debe tener al menos :min caracteres.',
        'letters_numbers' => 'La clave debe combinar letras y números.',
        'reused' => 'No puedes repetir ninguna de tus últimas :n claves.',
        'current_wrong' => 'La clave actual no es correcta.',
        'locked_event' => 'Tu cuenta se bloqueó por varios intentos fallidos de ingreso. Podrás volver a intentarlo en :minutes minutos o restablecer tu clave.',
        'changed_event' => 'Se cambió la clave de tu cuenta.',
    ],
    'mfa' => [
        'authenticator_label' => 'Aplicación autenticadora',
        'clock_skew' => 'El código corresponde a otra hora: el reloj de tu teléfono o del servidor está desfasado unos :minutes minutos. Activa la hora automática en el teléfono y revisa la hora del servidor.',
        'invalid_code' => 'El código no es válido o ya se usó.',
        'mfa_already_enabled' => 'La verificación en dos pasos ya está activa.',
        'mfa_enrollment_not_started' => 'Primero inicia la configuración de la aplicación autenticadora.',
        'mfa_not_enabled' => 'La verificación en dos pasos no está activa.',
        'mfa_required_by_policy' => 'Tu rol o tu organización exige la verificación en dos pasos: no se puede desactivar.',
        'enabled_event' => 'Se activó la verificación en dos pasos en tu cuenta.',
        'disabled_event' => 'Se desactivó la verificación en dos pasos en tu cuenta.',
    ],
];
