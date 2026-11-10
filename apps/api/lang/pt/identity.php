<?php

return [
    'password_reset' => [
        'subject' => 'Recupere sua senha do Ayni HR',
        'body' => "Recebemos uma solicitação para redefinir sua senha.\n\nUse este link (válido por :minutes minutos, uma única vez):\n:link\n\nSe você não solicitou, ignore esta mensagem.",
        'requested' => 'Se o e-mail pertencer a uma conta ativa, enviaremos um link para redefinir a senha.',
    ],
    'roles' => [
        'copy_of' => 'Cópia de :name',
        'system_immutable' => 'Papéis do sistema não podem ser editados nem excluídos; duplique-os para ajustar.',
        'unknown_permission' => 'A permissão :code não existe no catálogo ou não é deste tipo.',
        'cannot_grant' => 'Você não pode conceder permissões que não possui: :codes.',
    ],
];
