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
        'automation.failing' => [
            'in_app' => ['subject' => 'Una automatización está fallando', 'body' => 'La regla «{rule}» falló {count} veces seguidas. Último error: {error}'],
            'email' => ['subject' => 'Una automatización está fallando', 'body' => "Hola {name}:\n\nLa regla de automatización «{rule}», de la que eres responsable, falló {count} veces seguidas.\n\nÚltimo error: {error}\n\nRevísala aquí: {link}"],
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
        'people.dependent_majority' => [
            'in_app' => ['subject' => 'Un hijo derechohabiente cumplió la mayoría de edad', 'body' => 'Revisa si el hijo de {employee} sigue como derechohabiente (estudios, incapacidad) o dalo de baja.'],
            'email' => ['subject' => 'Revisa un derechohabiente', 'body' => "Hola {name}:\n\nUn hijo derechohabiente de {employee} cumplió la mayoría de edad en {organization}. Revisa si sigue como derechohabiente o dalo de baja.\n\nRevísalo aquí: {link}"],
        ],
        'people.bank_account_requested' => [
            'in_app' => ['subject' => 'Se registró una cuenta de pago a tu nombre', 'body' => 'Se registró la cuenta terminada en {last4} para tus pagos. Si no lo pediste, avisa a RRHH de inmediato.'],
            'email' => ['subject' => 'Se registró una cuenta de pago a tu nombre', 'body' => "Hola {name}:\n\nEn {organization} se registró la cuenta terminada en {last4} para tus pagos; queda pendiente de aprobación.\n\nSi no lo pediste, avisa a RRHH de inmediato."],
        ],
        'people.bank_account_changed' => [
            'in_app' => ['subject' => 'Tu cuenta de pago cambió', 'body' => 'Tus pagos se abonarán en la cuenta terminada en {last4}. Si no lo pediste, avisa a RRHH de inmediato.'],
            'email' => ['subject' => 'Tu cuenta de pago cambió', 'body' => "Hola {name}:\n\nEn {organization}, tus pagos se abonarán desde ahora en la cuenta terminada en {last4}.\n\nSi no lo pediste, avisa a RRHH de inmediato."],
        ],
        'absences.subsidy_started' => [
            'in_app' => ['subject' => '{employee} tiene {days} días de subsidio por tramitar', 'body' => 'El descanso de {employee} pasa a subsidio desde el {starts_on} ({days} días). Presenta la solicitud de reembolso y registra su seguimiento.'],
            'email' => ['subject' => '{employee} tiene {days} días de subsidio por tramitar', 'body' => "Hola {name}:\n\nEn {organization}, el descanso de {employee} pasa a subsidio desde el {starts_on} ({days} días). Presenta la solicitud de reembolso y registra su seguimiento aquí: {link}"],
        ],
        'exports.ready' => [
            'in_app' => ['subject' => 'Tu exportación de {resource} está lista', 'body' => 'El archivo de {resource} ({rows} filas) ya se puede descargar.'],
            'email' => ['subject' => 'Tu exportación de {resource} está lista', 'body' => "Hola {name}:\n\nEn {organization}, el archivo de {resource} ({rows} filas) ya se puede descargar aquí: {link}"],
        ],
        'exports.failed' => [
            'in_app' => ['subject' => 'No se pudo generar la exportación de {resource}', 'body' => 'La exportación de {resource} no se completó. Vuelve a pedirla; si se repite, avisa a soporte.'],
            'email' => ['subject' => 'No se pudo generar la exportación de {resource}', 'body' => "Hola {name}:\n\nEn {organization}, la exportación de {resource} no se completó. Vuelve a pedirla aquí: {link}"],
        ],
        'team.birthday' => [
            'in_app' => ['subject' => 'Cumpleaños de {employee}', 'body' => 'El {date} es el cumpleaños de {employee}, de tu equipo.'],
            'email' => ['subject' => 'Cumpleaños de {employee}', 'body' => "Hola {name}:\n\nEn {organization}, el {date} es el cumpleaños de {employee}, de tu equipo.\n\nMira las fechas de tu equipo aquí: {link}"],
        ],
        'team.anniversary' => [
            'in_app' => ['subject' => '{employee} cumple {years} años en la empresa', 'body' => 'El {date} {employee}, de tu equipo, cumple {years} años desde su ingreso.'],
            'email' => ['subject' => '{employee} cumple {years} años en la empresa', 'body' => "Hola {name}:\n\nEn {organization}, el {date} {employee}, de tu equipo, cumple {years} años desde su ingreso.\n\nMira las fechas de tu equipo aquí: {link}"],
        ],
        'contracts.probation_ending' => [
            'in_app' => ['subject' => 'El periodo de prueba de {employee} termina pronto', 'body' => 'El periodo de prueba de {employee} termina el {ends_on}. Revisa su desempeño antes de esa fecha.'],
            'email' => ['subject' => 'El periodo de prueba de {employee} termina pronto', 'body' => "Hola {name}:\n\nEn {organization}, el periodo de prueba de {employee} termina el {ends_on}. Revisa su desempeño y decide antes de esa fecha.\n\nRevísalo aquí: {link}"],
        ],
        'contracts.expiring' => [
            'in_app' => ['subject' => 'El contrato de {employee} vence en {days_left} días', 'body' => 'El contrato de {employee} vence el {ends_on}. {explanation} Decide si se renueva, se convierte a plazo indeterminado o no se renueva.'],
            'email' => ['subject' => 'El contrato de {employee} vence en {days_left} días', 'body' => "Hola {name}:\n\nEn {organization}, el contrato de {employee} vence el {ends_on} (faltan {days_left} días). {explanation}\n\nDecide si se renueva, se convierte a plazo indeterminado o no se renueva: {link}"],
        ],
        'contracts.cap_approaching' => [
            'in_app' => ['subject' => '{employee} se acerca al tope de contratos a plazo', 'body' => 'Al terminar el contrato que vence el {ends_on}, {employee} queda cerca del tope permitido. {explanation} Renovar podría desnaturalizar el contrato.'],
            'email' => ['subject' => '{employee} se acerca al tope de contratos a plazo', 'body' => "Hola {name}:\n\nEn {organization}, al terminar el contrato que vence el {ends_on}, {employee} queda cerca del tope de contratos a plazo. {explanation}\n\nRenovar podría desnaturalizar el contrato. Revísalo aquí: {link}"],
        ],
        'documents.expiring' => [
            'in_app' => ['subject' => '{document} vence en {days_left} días', 'body' => '{document} ({type}) de {employee} vence el {expires_on}. Pide o sube la versión renovada antes de esa fecha.'],
            'email' => ['subject' => '{document} vence en {days_left} días', 'body' => "Hola {name}:\n\nEn {organization}, {document} ({type}) de {employee} vence el {expires_on} (faltan {days_left} días).\n\nPide o sube la versión renovada antes de esa fecha: {link}"],
        ],
        'documents.expired' => [
            'in_app' => ['subject' => '{document} venció', 'body' => '{document} ({type}) de {employee} venció el {expires_on}. Sube la versión vigente para regularizar el legajo.'],
            'email' => ['subject' => '{document} venció', 'body' => "Hola {name}:\n\nEn {organization}, {document} ({type}) de {employee} venció el {expires_on}.\n\nSube la versión vigente para regularizar el legajo: {link}"],
        ],
        'documents.requested' => [
            'in_app' => ['subject' => 'Te piden un documento: {document}', 'body' => 'Recursos Humanos te pide {document} hasta el {due_on}. {instructions} Entrégalo desde tu portal, en Mis documentos.'],
            'email' => ['subject' => 'Te piden un documento: {document}', 'body' => "Hola {name}:\n\nEn {organization}, Recursos Humanos te pide {document} hasta el {due_on}.\n\n{instructions}\n\nEntrégalo desde tu portal: {link}"],
        ],
        'documents.rejected' => [
            'in_app' => ['subject' => 'Tu documento {document} fue observado', 'body' => 'Recursos Humanos observó {document} ({type}): {reason} Sube una versión corregida.'],
            'email' => ['subject' => 'Tu documento {document} fue observado', 'body' => "Hola {name}:\n\nEn {organization}, Recursos Humanos observó {document} ({type}): {reason}\n\nSube una versión corregida: {link}"],
        ],
        'certificates.requested' => [
            'in_app' => ['subject' => 'Constancia por emitir: {certificate}', 'body' => '{employee} pidió una {certificate}. Revísala y emítela o recházala.'],
            'email' => ['subject' => 'Constancia por emitir: {certificate}', 'body' => "Hola {name}:\n\nEn {organization}, {employee} pidió una {certificate} desde su portal.\n\nEmítela o recházala aquí: {link}"],
        ],
        'certificates.issued' => [
            'in_app' => ['subject' => 'Tu {certificate} está lista', 'body' => 'Tu {certificate} ya está en tus documentos. Puedes descargarla cuando quieras.'],
            'email' => ['subject' => 'Tu {certificate} está lista', 'body' => "Hola {name}:\n\nEn {organization}, tu {certificate} ya fue emitida y quedó en tus documentos.\n\nDescárgala aquí: {link}"],
        ],
        'certificates.rejected' => [
            'in_app' => ['subject' => 'Tu {certificate} no se emitió', 'body' => 'Recursos Humanos no emitió tu {certificate}: {reason}'],
            'email' => ['subject' => 'Tu {certificate} no se emitió', 'body' => "Hola {name}:\n\nEn {organization}, Recursos Humanos no emitió tu {certificate}: {reason}\n\nRevisa tus solicitudes aquí: {link}"],
        ],
        'help.article_review_due' => [
            'in_app' => ['subject' => 'Revisa «{title}»', 'body' => 'El artículo «{title}» de la ayuda de tu organización tiene revisión el {review_on}. Quedó marcado por revisar y sigue visible.'],
            'email' => ['subject' => 'Toca revisar «{title}»', 'body' => "Hola {name}:\n\nEn {organization}, el artículo «{title}» de la ayuda tiene revisión el {review_on}. Sigue visible para su audiencia mientras lo revisas.\n\nRevísalo aquí: {link}"],
        ],
        'tickets.created' => [
            'in_app' => ['subject' => 'Nueva solicitud {number}: {subject}', 'body' => '{employee} envió una solicitud de {category}: {subject}.'],
            'email' => ['subject' => 'Nueva solicitud {number}: {subject}', 'body' => "Hola {name}:\n\nEn {organization}, {employee} envió la solicitud {number} ({category}): {subject}.\n\nAtiéndela aquí: {link}"],
        ],
        'tickets.replied' => [
            'in_app' => ['subject' => '{employee} respondió la solicitud {number}', 'body' => '{employee} agregó información a {number}: {subject}.'],
            'email' => ['subject' => '{employee} respondió la solicitud {number}', 'body' => "Hola {name}:\n\nEn {organization}, {employee} respondió la solicitud {number}: {subject}.\n\nRevísala aquí: {link}"],
        ],
        'tickets.updated' => [
            'in_app' => ['subject' => 'Tu solicitud {number} cambió', 'body' => '{number} · {subject}: {status}.'],
            'email' => ['subject' => 'Tu solicitud {number} cambió', 'body' => "Hola {name}:\n\nEn {organization}, tu solicitud {number} ({subject}) ahora está: {status}.\n\nRevisa el detalle aquí: {link}"],
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
        'certificate_request' => 'constancia',
        'other' => 'trámite',
    ],
    'invalid_variables' => 'Variables no permitidas: :vars. Usa solo: :allowed.',
    'unknown_category' => 'Tipo de aviso desconocido.',
    'mandatory_in_app' => 'Este aviso es obligatorio y no se puede desactivar en la aplicación.',
    'template_exists' => 'Ya existe una plantilla para ese aviso, canal e idioma.',
    'unsupported_channel' => 'Ese canal aún no está disponible.',
    // NOT-5 · Mensajes directos por SMS y WhatsApp (variables entre llaves).
    'messages' => [
        'people.bank_account_requested' => [
            'sms' => '{organization}: se registró la cuenta terminada en {last4} para tus pagos. Si no lo pediste, avisa a RRHH.',
            'whatsapp' => '{organization}: se registró la cuenta terminada en {last4} para tus pagos. Si no lo pediste, avisa a RRHH.',
        ],
        'people.bank_account_changed' => [
            'sms' => '{organization}: tus pagos irán a la cuenta terminada en {last4}. Si no lo pediste, avisa a RRHH.',
            'whatsapp' => '{organization}: tus pagos irán a la cuenta terminada en {last4}. Si no lo pediste, avisa a RRHH.',
        ],
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
