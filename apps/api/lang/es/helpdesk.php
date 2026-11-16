<?php

return [
    'categories' => [
        'general' => 'Consulta general',
        'payslips' => 'Boletas y pagos',
        'documents' => 'Documentos del legajo',
        'benefits' => 'Beneficios y seguros',
        'schedule' => 'Horario y asistencia',
        'other' => 'Otra solicitud',
    ],
    'category_descriptions' => [
        'general' => 'Dudas sobre tu relación laboral o tus datos.',
        'payslips' => 'Boletas, depósitos, descuentos o montos que no cuadran.',
        'documents' => 'Documentos que necesitas o que debes entregar.',
        'benefits' => 'Seguro, EPS, beneficios de la empresa.',
        'schedule' => 'Tu horario, marcaciones o turnos.',
        'other' => 'Cualquier otra solicitud a Recursos Humanos.',
    ],
    'statuses' => [
        'new' => 'Nueva',
        'in_progress' => 'En atención',
        'waiting_info' => 'Esperando tu respuesta',
        'resolved' => 'Resuelta',
        'closed' => 'Cerrada',
        'cancelled' => 'Anulada',
    ],
    'code_taken' => 'Ya existe un tipo de solicitud con ese código.',
    'role_unknown' => 'Ese rol no existe en la organización.',
    'category_archived' => 'Ese tipo de solicitud está archivado.',
    'attachment_required' => 'Este tipo de solicitud exige un adjunto.',
    'closed_no_messages' => 'La solicitud está cerrada: reábrela o crea una nueva.',
    'internal_not_allowed' => 'Solo Recursos Humanos puede dejar notas internas.',
    'invalid_transition' => 'Esa acción no se puede aplicar a la solicitud en su estado actual.',
    'message_required' => 'Escribe qué información necesitas.',
    'assignee_unknown' => 'Ese usuario no está activo en la organización.',
];
