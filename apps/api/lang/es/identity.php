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
];
