<?php

/**
 * Textos por defecto de los avisos (NOT-1). Cada organización puede reemplazarlos en
 * Configuración → Mensajes. Las variables van entre llaves: {name}.
 */
return [
    'templates' => [
        'approval.pending' => [
            'in_app' => ['subject' => 'Solicitud por aprobar', 'body' => '{requester} envió una solicitud de {type} (nivel {level}).'],
            'email' => ['subject' => 'Tienes una solicitud por aprobar', 'body' => "Hola {name}:\n\nTienes una solicitud de {type} esperando tu decisión en {organization}.\n\nRevísala aquí: {link}\n\nPor seguridad, el detalle solo se ve al ingresar."],
        ],
        'approval.reminder' => [
            'in_app' => ['subject' => 'Recordatorio: solicitud por aprobar', 'body' => 'La solicitud de {type} de {requester} sigue esperando tu decisión.'],
            'email' => ['subject' => 'Recordatorio: tienes una solicitud por aprobar', 'body' => "Hola {name}:\n\nUna solicitud de {type} sigue esperando tu decisión en {organization}.\n\nRevísala aquí: {link}"],
        ],
        'approval.escalated' => [
            'in_app' => ['subject' => 'Solicitud escalada a ti', 'body' => 'La solicitud de {type} de {requester} venció su plazo y ahora te toca decidir.'],
            'email' => ['subject' => 'Una solicitud vencida pasó a tu cargo', 'body' => "Hola {name}:\n\nUna solicitud de {type} venció su plazo y ahora espera tu decisión en {organization}.\n\nRevísala aquí: {link}"],
        ],
        'approval.approved' => [
            'in_app' => ['subject' => 'Tu solicitud fue aprobada', 'body' => 'Tu solicitud de {type} fue aprobada.'],
            'email' => ['subject' => 'Tu solicitud fue aprobada', 'body' => "Hola {name}:\n\nTu solicitud de {type} fue aprobada.\n\nMira el detalle aquí: {link}"],
        ],
        'approval.rejected' => [
            'in_app' => ['subject' => 'Tu solicitud fue rechazada', 'body' => 'Tu solicitud de {type} fue rechazada: «{reason}».'],
            'email' => ['subject' => 'Tu solicitud fue rechazada', 'body' => "Hola {name}:\n\nTu solicitud de {type} fue rechazada. El motivo está en la aplicación.\n\nMíralo aquí: {link}"],
        ],
        'task.assigned' => [
            'in_app' => ['subject' => 'Nueva tarea asignada', 'body' => '{title} · vence {due_on}'],
            'email' => ['subject' => 'Tienes una nueva tarea', 'body' => "Hola {name}:\n\nTe asignaron una tarea en {organization}: {title}.\n\nÁbrela aquí: {link}"],
        ],
        'task.overdue' => [
            'in_app' => ['subject' => 'Tarea vencida', 'body' => '{title} venció el {due_on}.'],
            'email' => ['subject' => 'Tienes una tarea vencida', 'body' => "Hola {name}:\n\nLa tarea «{title}» venció el {due_on}.\n\nÁbrela aquí: {link}"],
        ],
        'automation.notice' => [
            'in_app' => ['subject' => '{title}', 'body' => '{message}'],
            'email' => ['subject' => '{title}', 'body' => "Hola {name}:\n\n{message}\n\nMás detalle aquí: {link}"],
        ],
        'automation.paused' => [
            'in_app' => ['subject' => 'Una automatización se pausó sola', 'body' => 'La regla «{rule}» se pausó porque se ejecutó demasiadas veces seguidas. Revísala antes de reactivarla.'],
            'email' => ['subject' => 'Una automatización se pausó sola', 'body' => "Hola {name}:\n\nLa regla de automatización «{rule}» se pausó sola porque se ejecutó demasiadas veces en una hora (posible bucle). Revísala antes de reactivarla.\n\nÁbrela aquí: {link}"],
        ],
        'account.security' => [
            'in_app' => ['subject' => 'Aviso de seguridad de tu cuenta', 'body' => '{event}'],
            'email' => ['subject' => 'Aviso de seguridad de tu cuenta', 'body' => "Hola {name}:\n\n{event}\n\nSi no fuiste tú, avisa a RRHH de inmediato."],
        ],
        'notifications.email_bounced' => [
            'in_app' => ['subject' => 'Un correo rebotó', 'body' => 'El correo de {user} rebotó y ya no se usa. Corrígelo en su cuenta o reactívalo desde el registro de entregas.'],
            'email' => ['subject' => 'Un correo de tu organización rebotó', 'body' => "Hola {name}:\n\nEl correo de {user} rebotó en {organization} y dejó de usarse; sus avisos le llegan en la aplicación o por mensaje al celular.\n\nCorrígelo aquí: {link}"],
        ],
    ],
    'subject_types' => [
        'vacation_request' => 'vacaciones',
        'permission_request' => 'permiso',
        'overtime_request' => 'horas extra',
        'expense_report' => 'gastos',
        'job_requisition' => 'requerimiento de personal',
        'position_request' => 'nueva posición',
        'compensation_change' => 'cambio de compensación',
        'other' => 'trámite',
    ],
    'invalid_variables' => 'Variables no permitidas: :vars. Usa solo: :allowed.',
    'unknown_category' => 'Tipo de aviso desconocido.',
    'mandatory_in_app' => 'Este aviso es obligatorio y no se puede desactivar en la aplicación.',
    'template_exists' => 'Ya existe una plantilla para ese aviso, canal e idioma.',
    'unsupported_channel' => 'Ese canal aún no está disponible.',
    // NOT-5 · Mensajes directos por SMS y WhatsApp (variables entre llaves).
    'messages' => [
        'identity.login_code' => [
            'sms' => 'GestiaHR: tu código de ingreso es {code}. Vence en {minutes} minutos. No lo compartas con nadie.',
            'whatsapp' => 'GestiaHR: tu código de ingreso es {code}. Vence en {minutes} minutos. No lo compartas con nadie.',
        ],
        'identity.activation_code' => [
            'sms' => 'GestiaHR: tu código para activar tu cuenta es {code}. Vence en {hours} horas. Ingresa con tu documento y este código.',
            'whatsapp' => 'GestiaHR: tu código para activar tu cuenta es {code}. Vence en {hours} horas. Ingresa con tu documento y este código.',
        ],
        'notifications.notice' => [
            'sms' => '{organization}: {title}. Ingresa para verlo: {link}',
            'whatsapp' => '{organization}: {title}. Ingresa para verlo: {link}',
        ],
    ],
    'approval_only_whatsapp' => 'Solo las plantillas de WhatsApp necesitan aprobación del proveedor.',
    'approval_reset' => 'La plantilla cambió y debe aprobarse otra vez antes de usarse en WhatsApp.',
    'sender' => [
        'invalid_address' => 'Escribe un correo válido para el remitente.',
        'nothing_to_verify' => 'La organización usa el remitente de la plataforma: no hay nada que verificar.',
        'already_verified' => 'El remitente ya está verificado.',
    ],
    'bounce' => [
        'nothing_to_clear' => 'Esta entrega no tiene una dirección rebotada que reactivar.',
        'invalid_signature' => 'La firma del proveedor no es válida.',
    ],
];
