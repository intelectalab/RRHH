<?php

return [
    'password_reset' => [
        'subject' => 'Recupere sua senha do Ayni HR',
        'body' => "Recebemos uma solicitação para redefinir sua senha.\n\nUse este link (válido por :minutes minutos, uma única vez):\n:link\n\nSe você não solicitou, ignore esta mensagem.",
        'requested' => 'Se o e-mail pertencer a uma conta ativa, enviaremos um link para redefinir a senha.',
    ],
];
