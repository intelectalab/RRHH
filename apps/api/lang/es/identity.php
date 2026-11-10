<?php

return [
    'password_reset' => [
        'subject' => 'Recupere su clave de Ayni HR',
        'body' => "Recibimos una solicitud para restablecer su clave.\n\nUse este enlace (válido por :minutes minutos, una sola vez):\n:link\n\nSi usted no lo pidió, ignore este mensaje.",
        'requested' => 'Si el correo pertenece a una cuenta activa, enviaremos un enlace para restablecer la clave.',
    ],
];
