<?php

return [
    'kinds' => [
        'employment_certificate' => 'Constancia de trabajo',
        'income_certificate' => 'Constancia de remuneraciones',
        'vacation_certificate' => 'Constancia de vacaciones',
        'cts_letter' => 'Carta de CTS',
        'withholding_certificate' => 'Certificado de retenciones',
        'other' => 'Otra constancia',
    ],
    'descriptions' => [
        'employment_certificate' => 'Acredita que trabajas o trabajaste en la empresa, con tu puesto y tu fecha de ingreso.',
        'income_certificate' => 'Acredita tu remuneración básica mensual vigente.',
        'vacation_certificate' => 'Lista los periodos de vacaciones que ya gozaste.',
    ],
    'date_format' => 'j \\d\\e F \\d\\e Y',
    'document_type' => 'Constancia de trabajo',
    'no_job' => 'sin puesto registrado',
    'kind_not_available' => 'Esa constancia no está disponible para ti.',
    'reason_required' => 'Indica el motivo del rechazo.',
    'inbox_title' => ':kind por emitir',
    'body' => [
        'intro' => ':legal_entity_name, con RUC :legal_entity_tax_id, deja constancia de lo siguiente:',
        'employment_current' => ':employee_name (código :employee_code) trabaja en la empresa desde el :hire_date, con el puesto de :job_title, hasta la fecha.',
        'employment_ended' => ':employee_name (código :employee_code) trabajó en la empresa desde el :hire_date hasta el :termination_date, con el puesto de :job_title.',
        'salary' => 'Su remuneración básica mensual vigente es de :salary.',
        'no_salary' => 'No hay una remuneración vigente registrada a la fecha.',
        'vacations' => 'Gozó de vacaciones en los siguientes periodos:',
        'vacation_period' => 'del :from al :to (:days días)',
        'no_vacations' => 'No registra periodos de vacaciones gozados.',
        'closing' => 'Se expide la presente a solicitud del interesado, para los fines que estime convenientes.',
        'closing_purpose' => 'Se expide la presente a solicitud del interesado, para: :purpose.',
        'place_date' => 'Emitida el :issued_on.',
        'footer' => 'Documento generado por GestiaHR · :legal_entity_name',
    ],
];
