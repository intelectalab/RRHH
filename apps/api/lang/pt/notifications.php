<?php

/**
 * Textos por defecto de los avisos (NOT-1). Cada organización puede reemplazarlos en
 * Configuración → Mensajes. Las variables van entre llaves: {name}.
 */
return [
    'templates' => [
        'approval.pending' => [
            'in_app' => ['subject' => 'Solicitação para aprovar', 'body' => '{requester} enviou uma solicitação de {type} (nível {level}).'],
            'email' => ['subject' => 'Você tem uma solicitação para aprovar', 'body' => "Olá {name},\n\nVocê tem uma solicitação de {type} aguardando sua decisão em {organization}.\n\nVeja aqui: {link}\n\nPor segurança, o detalhe só aparece após entrar."],
        ],
        'approval.reminder' => [
            'in_app' => ['subject' => 'Lembrete: solicitação para aprovar', 'body' => 'A solicitação de {type} de {requester} ainda aguarda sua decisão.'],
            'email' => ['subject' => 'Lembrete: você tem uma solicitação para aprovar', 'body' => "Olá {name},\n\nUma solicitação de {type} ainda aguarda sua decisão em {organization}.\n\nVeja aqui: {link}"],
        ],
        'approval.escalated' => [
            'in_app' => ['subject' => 'Solicitação escalada para você', 'body' => 'A solicitação de {type} de {requester} venceu o prazo e agora depende de você.'],
            'email' => ['subject' => 'Uma solicitação vencida passou para você', 'body' => "Olá {name},\n\nUma solicitação de {type} venceu o prazo e agora aguarda sua decisão em {organization}.\n\nVeja aqui: {link}"],
        ],
        'approval.approved' => [
            'in_app' => ['subject' => 'Sua solicitação foi aprovada', 'body' => 'Sua solicitação de {type} foi aprovada.'],
            'email' => ['subject' => 'Sua solicitação foi aprovada', 'body' => "Olá {name},\n\nSua solicitação de {type} foi aprovada.\n\nVeja o detalhe aqui: {link}"],
        ],
        'approval.rejected' => [
            'in_app' => ['subject' => 'Sua solicitação foi rejeitada', 'body' => 'Sua solicitação de {type} foi rejeitada: “{reason}”.'],
            'email' => ['subject' => 'Sua solicitação foi rejeitada', 'body' => "Olá {name},\n\nSua solicitação de {type} foi rejeitada. O motivo está no aplicativo.\n\nVeja aqui: {link}"],
        ],
        'task.assigned' => [
            'in_app' => ['subject' => 'Nova tarefa atribuída', 'body' => '{title} · vence {due_on}'],
            'email' => ['subject' => 'Você tem uma nova tarefa', 'body' => "Olá {name},\n\nVocê recebeu uma tarefa em {organization}: {title}.\n\nAbra aqui: {link}"],
        ],
        'task.overdue' => [
            'in_app' => ['subject' => 'Tarefa vencida', 'body' => '{title} venceu em {due_on}.'],
            'email' => ['subject' => 'Você tem uma tarefa vencida', 'body' => "Olá {name},\n\nA tarefa “{title}” venceu em {due_on}.\n\nAbra aqui: {link}"],
        ],
        'automation.notice' => [
            'in_app' => ['subject' => '{title}', 'body' => '{message}'],
            'email' => ['subject' => '{title}', 'body' => "Olá {name},\n\n{message}\n\nMais detalhes aqui: {link}"],
        ],
        'automation.paused' => [
            'in_app' => ['subject' => 'Uma automação foi pausada', 'body' => 'A regra “{rule}” foi pausada porque foi executada muitas vezes seguidas. Revise-a antes de reativá-la.'],
            'email' => ['subject' => 'Uma automação foi pausada', 'body' => "Olá {name},\n\nA regra de automação “{rule}” foi pausada automaticamente porque foi executada muitas vezes em uma hora (possível loop). Revise-a antes de reativá-la.\n\nAbra aqui: {link}"],
        ],
        'account.security' => [
            'in_app' => ['subject' => 'Aviso de segurança da conta', 'body' => '{event}'],
            'email' => ['subject' => 'Aviso de segurança da conta', 'body' => "Olá {name},\n\n{event}\n\nSe não foi você, avise o RH imediatamente."],
        ],
        'organization.suspended' => [
            'in_app' => ['subject' => 'Sua organização foi suspensa', 'body' => 'Sua organização foi suspensa: {reason}. Os dados são mantidos; fale com o suporte para regularizar.'],
            'email' => ['subject' => 'Sua organização foi suspensa', 'body' => "Olá {name}:\n\nA organização {organization} foi suspensa. Motivo: {reason}.\n\nEnquanto estiver suspensa, seus usuários não podem entrar. Os dados são mantidos. Fale com o suporte do GestiaHR para regularizá-la."],
        ],
        'organization.reactivated' => [
            'in_app' => ['subject' => 'Sua organização voltou a ficar ativa', 'body' => 'A organização está ativa novamente. Todos os dados continuam disponíveis.'],
            'email' => ['subject' => 'Sua organização voltou a ficar ativa', 'body' => "Olá {name}:\n\nA organização {organization} está ativa novamente e seus usuários já podem entrar. Todos os dados continuam disponíveis.\n\n{link}"],
        ],
        'organization.activated' => [
            'in_app' => ['subject' => 'Sua organização já está ativa', 'body' => 'O teste terminou e a organização ficou ativa.'],
            'email' => ['subject' => 'Sua organização já está ativa', 'body' => "Olá {name}:\n\nA organização {organization} passou de teste para ativa. Tudo o que foi registrado durante o teste é mantido.\n\n{link}"],
        ],
        'organization.cancelled' => [
            'in_app' => ['subject' => 'Sua organização foi cancelada', 'body' => 'A organização foi cancelada: {reason}. Os dados são mantidos até {retention_until}.'],
            'email' => ['subject' => 'Sua organização foi cancelada', 'body' => "Olá {name}:\n\nA organização {organization} foi cancelada. Motivo: {reason}.\n\nOs dados são mantidos até {retention_until}. Até essa data você pode pedir ao suporte uma exportação completa ou a reativação."],
        ],
        'notifications.email_bounced' => [
            'in_app' => ['subject' => 'Um e-mail voltou', 'body' => 'O e-mail de {user} voltou e não é mais usado. Corrija na conta da pessoa ou reative pelo registro de envios.'],
            'email' => ['subject' => 'Um e-mail da sua organização voltou', 'body' => "Olá {name},\n\nO e-mail de {user} voltou em {organization} e deixou de ser usado; os avisos chegam no aplicativo ou por mensagem no celular.\n\nCorrija aqui: {link}"],
        ],
        'limits.warning' => [
            'in_app' => ['subject' => 'Você está perto de um limite do plano', 'body' => '{metric}: {current} de {limit} ({percent} %). Revise o consumo antes de chegar ao limite.'],
            'email' => ['subject' => 'Sua organização está perto de um limite do plano', 'body' => "Olá {name}:\n\nEm {organization}, {metric} está em {current} de {limit} ({percent} %). Ao chegar ao limite, não será possível fazer novos cadastros ou envios; o que já existe continua funcionando.\n\nVeja o consumo e como ampliá-lo aqui: {link}"],
        ],
        'limits.reached' => [
            'in_app' => ['subject' => 'Você atingiu um limite do plano', 'body' => '{metric}: {current} de {limit}. Novos cadastros ou envios ficam bloqueados até ampliar o plano ou liberar espaço.'],
            'email' => ['subject' => 'Sua organização atingiu um limite do plano', 'body' => "Olá {name}:\n\nEm {organization}, {metric} chegou a {current} de {limit}. A partir de agora não é possível fazer novos cadastros ou envios desse tipo; tudo o que foi registrado continua disponível.\n\nAmplie o plano ou adicione um complemento aqui: {link}"],
        ],
    ],
    'subject_types' => [
        'vacation_request' => 'férias',
        'permission_request' => 'licença',
        'overtime_request' => 'horas extras',
        'expense_report' => 'despesas',
        'job_requisition' => 'requisição de pessoal',
        'position_request' => 'nova posição',
        'compensation_change' => 'alteração de remuneração',
        'other' => 'solicitação',
    ],
    'invalid_variables' => 'Variáveis não permitidas: :vars. Use apenas: :allowed.',
    'unknown_category' => 'Tipo de aviso desconhecido.',
    'mandatory_in_app' => 'Este aviso é obrigatório e não pode ser desativado no aplicativo.',
    'template_exists' => 'Já existe um modelo para esse aviso, canal e idioma.',
    'unsupported_channel' => 'Esse canal ainda não está disponível.',
    // NOT-5 · Mensajes directos por SMS y WhatsApp (variables entre llaves).
    'messages' => [
        'identity.login_code' => [
            'sms' => 'GestiaHR: seu código de acesso é {code}. Vence em {minutes} minutos. Não o compartilhe.',
            'whatsapp' => 'GestiaHR: seu código de acesso é {code}. Vence em {minutes} minutos. Não o compartilhe.',
        ],
        'identity.activation_code' => [
            'sms' => 'GestiaHR: seu código para ativar a conta é {code}. Vence em {hours} horas. Entre com seu documento e este código.',
            'whatsapp' => 'GestiaHR: seu código para ativar a conta é {code}. Vence em {hours} horas. Entre com seu documento e este código.',
        ],
        'notifications.notice' => [
            'sms' => '{organization}: {title}. Entre para ver: {link}',
            'whatsapp' => '{organization}: {title}. Entre para ver: {link}',
        ],
    ],
    'approval_only_whatsapp' => 'Somente os modelos de WhatsApp precisam da aprovação do provedor.',
    'approval_reset' => 'O modelo mudou e precisa ser aprovado de novo antes de ser usado no WhatsApp.',
    'sender' => [
        'invalid_address' => 'Informe um e-mail válido para o remetente.',
        'nothing_to_verify' => 'A organização usa o remetente da plataforma: não há nada para verificar.',
        'already_verified' => 'O remetente já está verificado.',
    ],
    'bounce' => [
        'nothing_to_clear' => 'Este envio não tem um endereço devolvido para reativar.',
        'invalid_signature' => 'A assinatura do provedor não é válida.',
    ],
];
