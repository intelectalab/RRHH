<?php

// SEG-12 · Exportação de listas.
return [
    'columns' => [
        'employee_code' => 'Código',
        'person_name' => 'Colaborador',
        'legal_entity_name' => 'Empresa',
        'job_profile_name' => 'Cargo',
        'position_title' => 'Posição',
        'org_unit_name' => 'Unidade',
        'site_name' => 'Local',
        'manager_name' => 'Gestor',
        'hire_date' => 'Data de admissão',
        'display_name' => 'Nome',
        'email' => 'E-mail',
        'status' => 'Status',
        'roles' => 'Perfis',
        'last_login_at' => 'Último acesso',
        'name' => 'Razão social',
        'trade_name' => 'Nome fantasia',
        'tax_id' => 'Identificação fiscal',
        'country' => 'País',
        'currency' => 'Moeda',
        'timezone' => 'Fuso horário',
    ],
    'watermark' => 'Exportado por :name em :date',
    'resource_invalid' => 'Esta lista não pode ser exportada.',
    'format_invalid' => 'Por enquanto só é possível exportar em CSV.',
    'filter_invalid' => 'Filtro não permitido para esta lista: :filter.',
    'not_ready' => 'A exportação ainda não está pronta.',
    'expired' => 'A exportação expirou; gere uma nova.',
];
