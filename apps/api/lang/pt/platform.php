<?php

return [
    'invalid_credentials' => 'O e-mail ou a senha estão incorretos, ou a conta está bloqueada temporariamente.',
    'invalid_code' => 'O código não é válido ou já foi usado.',
    'clock_skew' => 'O código corresponde a outro horário: o relógio do seu telefone ou do servidor está cerca de :minutes minutos defasado.',
    'mfa_already_enabled' => 'A verificação em duas etapas já está configurada. Para alterá-la, peça que a redefinam.',
    'mfa_enrollment_not_started' => 'Primeiro inicie a configuração do aplicativo autenticador.',
    'mfa_not_enabled' => 'A verificação em duas etapas ainda não está configurada.',
    'roles' => [
        'superadmin' => 'Proprietário',
        'support' => 'Suporte',
        'billing' => 'Financeiro',
        'readonly' => 'Operações (somente leitura)',
    ],
    'tenants' => [
        'slug_taken' => 'Esse subdomínio já está em uso ou é reservado.',
        'reason_required' => 'Informe o motivo: ele fica no histórico e no aviso à organização.',
        'retention_expired' => 'O período de retenção da organização terminou: ela não pode mais ser reativada.',
        'unknown_country' => 'O país não existe no catálogo.',
        'unknown_currency' => 'A moeda não existe no catálogo.',
        'unknown_timezone' => 'O fuso horário não é válido (por exemplo, America/Lima).',
        'unknown_regime' => 'O regime trabalhista não corresponde ao país.',
    ],
    'modules' => [
        'core' => 'Os módulos base não podem ser retirados.',
        'required_by' => 'Outros módulos ativos dependem deste: :modules. Retire-os primeiro.',
    ],
];
