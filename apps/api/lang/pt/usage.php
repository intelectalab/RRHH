<?php

/**
 * SAS-6 · Limites e consumo do plano. Cada mensagem de limite explica como ampliá-lo.
 */
return [
    'metrics' => [
        'employees' => 'Colaboradores ativos',
        'admins' => 'Administradores',
        'legal_entities' => 'Empresas ativas',
        'storage_mb' => 'Armazenamento (MB)',
        'api_calls' => 'Chamadas à API no mês',
    ],
    'reached' => [
        'employees' => 'Seu plano permite até :limit colaboradores ativos e todos estão em uso. Para admitir mais pessoas, amplie o plano ou adicione um complemento de colaboradores (Configurações → Consumo do plano), ou desligue quem não trabalha mais.',
        'admins' => 'Seu plano permite até :limit administradores. Para dar esta função a outra pessoa, retire-a de alguém ou amplie o plano em Configurações → Consumo do plano.',
        'legal_entities' => 'Seu plano permite até :limit empresas ativas. Para adicionar outra, arquive uma que não usa mais ou amplie o plano em Configurações → Consumo do plano.',
        'storage_mb' => 'O armazenamento do seu plano (:limit MB) não é suficiente para este arquivo. Você continua podendo ver e baixar o que está guardado; para enviar mais, exclua arquivos desnecessários ou amplie o plano em Configurações → Consumo do plano.',
        'default' => 'Você atingiu o limite do seu plano (:limit). Amplie o plano ou adicione um complemento em Configurações → Consumo do plano.',
    ],
];
