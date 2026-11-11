<?php

return [
    'entities' => [
        'legal_entities' => 'Empresas',
        'employees' => 'Colaboradores',
        'contracts' => 'Contratos',
        'vacation_balances' => 'Saldos de férias',
        'salaries' => 'Salários',
        'attendance' => 'Ponto',
        'concepts' => 'Rubricas',
        'history' => 'Históricos',
        'org_structure' => 'Estrutura',
        'dependents' => 'Dependentes',
        'positions' => 'Posições',
    ],
    'columns' => [
        'legal_entities' => [
            'name' => 'Razão social',
            'tax_id' => 'Número fiscal',
            'trade_name' => 'Nome fantasia',
            'payroll_regime' => 'Regime trabalhista',
            'country' => 'País',
            'currency' => 'Moeda',
            'timezone' => 'Fuso horário',
        ],
    ],
    'file' => [
        'empty' => 'O arquivo está vazio ou não pôde ser lido.',
        'no_headers' => 'A primeira linha do arquivo deve ter os nomes das colunas.',
        'duplicate_headers' => 'Há colunas com o mesmo nome na primeira linha.',
        'no_rows' => 'O arquivo não tem linhas de dados abaixo dos nomes das colunas.',
        'too_many_rows' => 'O arquivo ultrapassa as :max linhas permitidas; divida-o em partes.',
        'encoding' => 'A codificação do arquivo não é reconhecida; salve-o como CSV UTF-8.',
        'unreadable' => 'Não foi possível ler o arquivo. Use CSV ou Excel (.xlsx).',
    ],
    'mapping' => [
        'invalid' => 'O mapeamento deve indicar, para cada coluna, o cabeçalho do arquivo.',
        'unknown_column' => 'A coluna «:column» não existe para este tipo de dados.',
        'unknown_header' => 'O cabeçalho «:header» não está no arquivo.',
        'header_twice' => 'O cabeçalho «:header» está atribuído a duas colunas.',
        'missing_required' => 'Falta atribuir a coluna obrigatória «:column».',
    ],
    'errors' => [
        'required' => '«:column» é obrigatório.',
        'duplicate_in_file' => 'Repete os dados da linha :row do mesmo arquivo.',
    ],
    'warnings' => [
        'duplicate_file' => 'Este arquivo já foi enviado antes para o mesmo tipo de dados. Verifique se não o está importando duas vezes.',
        'sensitive_ignored' => 'A coluna «:column» é ignorada: você não tem permissão para importar esse dado.',
    ],
    'csv' => [
        'row' => 'Linha',
        'reason' => 'Motivo',
    ],
    'entity_invalid' => 'Esse tipo de dados não pode ser importado.',
    'confirm_required' => 'Revise a pré-visualização e confirme para importar.',
    'nothing_to_import' => 'Não há linhas válidas para importar.',
    'revert_blocked' => 'Não é possível desfazer: :n registros do lote já são usados em outros processos.',
    'no_errors' => 'Esta importação não tem linhas com erro.',
    'failed_unexpected' => 'Ocorreu um erro inesperado ao importar; nenhum registro foi salvo.',
];
