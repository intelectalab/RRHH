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
];
