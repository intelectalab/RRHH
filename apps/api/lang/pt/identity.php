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
        'name_taken' => 'Já existe um papel ativo com esse nome; renomeie-o antes de restaurar este.',
    ],
    'users' => [
        'scope_units_required' => 'Indique pelo menos uma empresa, unidade física ou área para esse alcance.',
        'unknown_scope_unit' => 'Uma das empresas, unidades físicas ou áreas não existe nesta organização.',
        'invite_without_email' => 'O usuário não tem e-mail: entregue um código de ativação pelo RH.',
        'reset_without_email' => 'O usuário não tem e-mail para receber o link.',
        'unknown_role' => 'Um ou mais papéis não existem nesta organização.',
        'last_admin' => 'A organização não pode ficar sem um Administrador da empresa ativo.',
    ],
    'invitation' => [
        'subject' => 'Ative sua conta Ayni HR',
        'body' => "Sua empresa criou uma conta Ayni HR para você.\n\nAtive-a e defina sua senha com este link (válido por :hours horas):\n:link",
    ],
];
