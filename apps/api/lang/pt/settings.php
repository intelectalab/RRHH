<?php

return [
    'scope_not_allowed' => 'Este parâmetro não pode ser definido nesse âmbito.',
    'invalid_value' => 'O valor não é válido para este parâmetro.',
    'later_value_exists' => 'Já existe um valor agendado para uma data posterior; altere-o antes.',
    'national_calendar' => 'Feriados nacionais (:country)',
    'holiday_exists' => 'Já existe um feriado em :date neste calendário.',
    'past_holiday_locked' => 'Um feriado que já passou não pode ser removido: frequência e folha já o usaram.',
    'national_holiday_locked' => 'Os feriados nacionais são definidos por lei: não podem ser removidos.',
    'no_exchange_rate' => 'Não há câmbio de :from para :to vigente em :date.',
    'rate_exists' => 'Já existe uma taxa para esse par e data; publique uma com outra data.',
    'custom_field_unknown' => 'Este campo não existe para este registro.',
    'custom_field_forbidden' => 'Você não tem permissão para este campo.',
    'custom_field_required' => 'Este campo é obrigatório.',
    'custom_field_key_taken' => 'Já existe um campo com essa chave para esta entidade (pode estar na lixeira).',
    'custom_field_type_locked' => 'O campo já tem valores salvos: o tipo não pode mudar.',
    'custom_field_options_required' => 'Informe as opções da lista.',
    'custom_field_permission_required' => 'Um campo sensível precisa de uma permissão por campo.',
    'calendar' => [
        'invalid_range' => 'Informe um intervalo válido: from e to no formato AAAA-MM-DD, to não anterior a from e de até 100 dias.',
        'unknown_source' => 'A fonte «:source» não existe no calendário.',
    ],
    'saved_view_filter' => 'Só é possível filtrar por resource.',
    'saved_view_limit' => 'Você atingiu o máximo de :max visões salvas nesta tabela. Apague uma para salvar outra.',
    'saved_view_not_owner' => 'Só quem criou a visão pode alterá-la ou apagá-la.',
    'saved_view_name_taken' => 'Você já tem uma visão com esse nome nesta tabela.',
];
