<?php

return [
    'kinds' => [
        'employment_certificate' => 'Declaração de vínculo',
        'income_certificate' => 'Declaração de remuneração',
        'vacation_certificate' => 'Declaração de férias',
        'cts_letter' => 'Carta de CTS',
        'withholding_certificate' => 'Comprovante de retenções',
        'other' => 'Outra declaração',
    ],
    'descriptions' => [
        'employment_certificate' => 'Comprova que você trabalha ou trabalhou na empresa, com seu cargo e data de admissão.',
        'income_certificate' => 'Comprova sua remuneração básica mensal vigente.',
        'vacation_certificate' => 'Lista os períodos de férias que você já gozou.',
    ],
    'date_format' => 'j \\d\\e F \\d\\e Y',
    'document_type' => 'Declaração de vínculo',
    'no_job' => 'sem cargo registrado',
    'kind_not_available' => 'Essa declaração não está disponível para você.',
    'reason_required' => 'Informe o motivo da recusa.',
    'inbox_title' => ':kind para emitir',
    'body' => [
        'intro' => ':legal_entity_name, com identificação fiscal :legal_entity_tax_id, declara o seguinte:',
        'employment_current' => ':employee_name (código :employee_code) trabalha na empresa desde :hire_date, no cargo de :job_title, até a presente data.',
        'employment_ended' => ':employee_name (código :employee_code) trabalhou na empresa de :hire_date a :termination_date, no cargo de :job_title.',
        'salary' => 'Sua remuneração básica mensal vigente é de :salary.',
        'no_salary' => 'Não há remuneração vigente registrada nesta data.',
        'vacations' => 'Gozou férias nos seguintes períodos:',
        'vacation_period' => 'de :from a :to (:days dias)',
        'no_vacations' => 'Não há períodos de férias gozados registrados.',
        'closing' => 'A presente é emitida a pedido do interessado, para os fins que julgar convenientes.',
        'closing_purpose' => 'A presente é emitida a pedido do interessado, para: :purpose.',
        'place_date' => 'Emitida em :issued_on.',
        'footer' => 'Documento gerado pelo GestiaHR · :legal_entity_name',
    ],
];
