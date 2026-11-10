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
];
