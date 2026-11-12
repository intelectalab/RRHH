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
        'organization.suspended' => [
            'in_app' => ['subject' => 'Tu organización fue suspendida', 'body' => 'Tu organización fue suspendida: {reason}. Los datos se conservan; comunícate con soporte para regularizar.'],
            'email' => ['subject' => 'Tu organización fue suspendida', 'body' => "Hola {name}:\n\nLa organización {organization} fue suspendida. Motivo: {reason}.\n\nMientras siga suspendida, sus usuarios no pueden ingresar. Los datos se conservan. Comunícate con soporte de GestiaHR para regularizarla."],
        ],
        'organization.reactivated' => [
            'in_app' => ['subject' => 'Tu organización volvió a estar activa', 'body' => 'La organización está activa otra vez. Todos sus datos siguen disponibles.'],
            'email' => ['subject' => 'Tu organización volvió a estar activa', 'body' => "Hola {name}:\n\nLa organización {organization} está activa otra vez y sus usuarios ya pueden ingresar. Todos los datos siguen disponibles.\n\n{link}"],
        ],
        'organization.activated' => [
            'in_app' => ['subject' => 'Tu organización ya está activa', 'body' => 'La prueba terminó y la organización quedó activa.'],
            'email' => ['subject' => 'Tu organización ya está activa', 'body' => "Hola {name}:\n\nLa organización {organization} pasó de prueba a activa. Todo lo que registraron durante la prueba se conserva.\n\n{link}"],
        ],
        'organization.cancelled' => [
            'in_app' => ['subject' => 'Tu organización fue dada de baja', 'body' => 'La organización fue dada de baja: {reason}. Los datos se conservan hasta el {retention_until}.'],
            'email' => ['subject' => 'Tu organización fue dada de baja', 'body' => "Hola {name}:\n\nLa organización {organization} fue dada de baja. Motivo: {reason}.\n\nSus datos se conservan hasta el {retention_until}. Hasta esa fecha puedes pedir a soporte una exportación completa o la reactivación."],
        ],
        'notifications.email_bounced' => [
            'in_app' => ['subject' => 'Un correo rebotó', 'body' => 'El correo de {user} rebotó y ya no se usa. Corrígelo en su cuenta o reactívalo desde el registro de entregas.'],
            'email' => ['subject' => 'Un correo de tu organización rebotó', 'body' => "Hola {name}:\n\nEl correo de {user} rebotó en {organization} y dejó de usarse; sus avisos le llegan en la aplicación o por mensaje al celular.\n\nCorrígelo aquí: {link}"],
        ],
        'limits.warning' => [
            'in_app' => ['subject' => 'Te acercas al límite del plan', 'body' => '{metric}: {current} de {limit} ({percent} %). Revisa el consumo antes de llegar al tope.'],
            'email' => ['subject' => 'Tu organización se acerca a un límite del plan', 'body' => "Hola {name}:\n\nEn {organization}, {metric} va en {current} de {limit} ({percent} %). Al llegar al tope no se podrán hacer nuevas altas o subidas; lo que ya existe sigue funcionando.\n\nRevisa el consumo y cómo ampliarlo aquí: {link}"],
        ],
        'limits.reached' => [
            'in_app' => ['subject' => 'Llegaste al límite del plan', 'body' => '{metric}: {current} de {limit}. Las nuevas altas o subidas quedan bloqueadas hasta ampliar el plan o liberar espacio.'],
            'email' => ['subject' => 'Tu organización llegó a un límite del plan', 'body' => "Hola {name}:\n\nEn {organization}, {metric} llegó a {current} de {limit}. Desde ahora no se pueden hacer nuevas altas o subidas de ese tipo; todo lo registrado sigue disponible.\n\nAmplía el plan o suma un complemento aquí: {link}"],
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
