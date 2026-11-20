<?php

/**
 * NOT-1 · Catálogo de tipos de aviso.
 *  - variables: las únicas que admite la plantilla (se validan al editarla).
 *  - link: pantalla donde se resuelve; {variables} se reemplazan.
 *  - channels: canales que el tipo usa por defecto (el usuario puede apagar los no obligatorios).
 *  - mandatory: RN-NOT-05, el usuario no puede desactivar el aviso en la aplicación.
 *  - urgent: no se difiere por el horario de silencio (RN-NOT-03).
 * RN-NOT-02: los textos para canales externos no llevan sueldos, salud ni denuncias: solo el aviso y el enlace.
 */
return [
    // Canales con adaptador en el motor de avisos. Push (NOT-4) sale solo a quien registró un dispositivo
    // y no lo apagó en sus preferencias; SMS y WhatsApp sirven al aviso corto sin correo (NOT-5).
    'channels' => ['in_app', 'email', 'push'],

    // NOT-4 · Web Push: clave pública VAPID (la privada vive solo en el proveedor real).
    'push' => ['vapid_public_key' => env('PUSH_VAPID_PUBLIC_KEY')],

    'categories' => [
        'approval.pending' => ['variables' => ['requester', 'type', 'level'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.reminder' => ['variables' => ['requester', 'type'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.escalated' => ['variables' => ['requester', 'type'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.approved' => ['variables' => ['type'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.rejected' => ['variables' => ['type', 'reason'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'task.assigned' => ['variables' => ['title', 'due_on'], 'link' => '/tareas?id={task_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'task.overdue' => ['variables' => ['title', 'due_on'], 'link' => '/tareas?id={task_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'automation.notice' => ['variables' => ['title', 'message'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // WFL-7 · Una regla con fallas seguidas avisa una vez a su responsable.
        'automation.failing' => ['variables' => ['rule', 'count', 'error'], 'link' => '/configuracion/automatizaciones', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => true],
        'automation.paused' => ['variables' => ['rule'], 'link' => '/configuracion/automatizaciones', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => true],
        'account.security' => ['variables' => ['event'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.suspended' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.reactivated' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.activated' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.cancelled' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        // NOT-3: la dirección de correo de una persona rebotó; RRHH la corrige o la reactiva.
        'notifications.email_bounced' => ['variables' => ['user', 'reason'], 'link' => '/configuracion/mensajes?vista=entregas&estado=bounced', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // SAS-11: comprobante del SaaS emitido, por vencer o vencido (a los administradores de la organización).
        'billing.invoice_issued' => ['variables' => ['number', 'total', 'due_on'], 'link' => '/configuracion/facturacion', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => false],
        'billing.due_soon' => ['variables' => ['number', 'total', 'due_on'], 'link' => '/configuracion/facturacion', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => false],
        'billing.overdue' => ['variables' => ['number', 'total', 'due_on', 'suspend_on'], 'link' => '/configuracion/facturacion', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        // SAS-6 · RN-SAS-02 · RN-SAS-03: el consumo se acerca al tope del plan o llega a él.
        'limits.warning' => ['variables' => ['metric', 'current', 'limit', 'percent'], 'link' => '/configuracion/consumo', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // COL-6: un hijo derechohabiente cumplió la mayoría de edad; RRHH lo revisa en la ficha.
        'people.dependent_majority' => ['variables' => ['employee'], 'link' => '/personas/colaboradores/{employment_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // COL-8 · RN-COL-11: se registró o cambió la cuenta de pago del titular (solo los últimos dígitos).
        'people.bank_account_requested' => ['variables' => ['last4'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'people.bank_account_changed' => ['variables' => ['last4'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        // UI-11 · Exportación grande generada en segundo plano: lista para descargar o fallida (a quien la pidió).
        'exports.ready' => ['variables' => ['resource', 'rows'], 'link' => '/exportaciones', 'channels' => ['in_app'], 'mandatory' => false, 'urgent' => false],
        'exports.failed' => ['variables' => ['resource'], 'link' => '/exportaciones', 'channels' => ['in_app'], 'mandatory' => false, 'urgent' => false],
        // MSS-4 · Cumpleaños y aniversario de ingreso de alguien del equipo (al jefe; solo el día, nunca la edad).
        'team.birthday' => ['variables' => ['employee', 'date'], 'link' => '/mi-equipo/fechas', 'channels' => ['in_app'], 'mandatory' => false, 'urgent' => false],
        'team.anniversary' => ['variables' => ['employee', 'date', 'years'], 'link' => '/mi-equipo/fechas', 'channels' => ['in_app'], 'mandatory' => false, 'urgent' => false],
        // CON-2: el periodo de prueba de un colaborador termina pronto; el jefe y RRHH lo revisan en la ficha.
        'contracts.probation_ending' => ['variables' => ['employee', 'ends_on'], 'link' => '/personas/colaboradores/{employment_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // CON-4 · RN-CON-04: un contrato vence pronto (con el acumulado frente al tope) o el acumulado se acerca al tope.
        'contracts.expiring' => ['variables' => ['employee', 'ends_on', 'days_left', 'explanation'], 'link' => '/personas/contratos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // CON-6 · Solicitud de teletrabajo cuyo plazo legal de respuesta vence pronto.
        // VAC-5 · Periodos vacacionales vencidos o por vencer (al jefe y a RRHH, una vez por periodo).
        'vacations.accumulation' => ['variables' => ['employee', 'explanation'], 'link' => '/tiempo/vacaciones/programacion', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'remote_work.response_due' => ['variables' => ['employee', 'due_on'], 'link' => '/personas/teletrabajo', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'contracts.cap_approaching' => ['variables' => ['employee', 'ends_on', 'explanation'], 'link' => '/personas/contratos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // AUS-3: un descanso pasó a subsidio; quien gestiona subsidios presenta el reembolso (sin datos de salud).
        'absences.subsidy_started' => ['variables' => ['employee', 'days', 'starts_on'], 'link' => '/tiempo/subsidios', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // DOC-2 · RN-DOC-09: un documento vence pronto o venció (al responsable y al titular); ME-12: uno entregado fue rechazado.
        'documents.expiring' => ['variables' => ['document', 'type', 'employee', 'expires_on', 'days_left'], 'link' => '/documentos/vencimientos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'documents.expired' => ['variables' => ['document', 'type', 'employee', 'expires_on'], 'link' => '/documentos/vencimientos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'documents.rejected' => ['variables' => ['document', 'type', 'reason'], 'link' => '/documentos/vencimientos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // DOC-3 · Documento solicitado al colaborador: lo entrega desde su portal.
        'documents.requested' => ['variables' => ['document', 'due_on', 'instructions'], 'link' => '/mi', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // ESS-4: constancia por emitir (a RRHH), emitida o rechazada (al colaborador).
        // DOC-6: documento por firmar (al firmante de turno), firma completada o rechazada (a quien lo envió).
        'signatures.requested' => ['variables' => ['document'], 'link' => '/mi/firmas', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'signatures.completed' => ['variables' => ['document'], 'link' => '/documentos/firmas', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'signatures.declined' => ['variables' => ['document', 'signer', 'reason'], 'link' => '/documentos/firmas', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'certificates.requested' => ['variables' => ['certificate', 'employee'], 'link' => '/personas/constancias', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'certificates.issued' => ['variables' => ['certificate'], 'link' => '/mi/solicitudes', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // AYU-4 · RN-AYU-04: artículo de la organización por revisar (al responsable).
        'help.article_review_due' => ['variables' => ['title', 'review_on'], 'link' => '/configuracion/ayuda', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'certificates.rejected' => ['variables' => ['certificate', 'reason'], 'link' => '/mi/solicitudes', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // ESS-5: solicitud a RRHH nueva o respondida (a quien la atiende) y cambio de estado (al solicitante).
        'tickets.created' => ['variables' => ['number', 'subject', 'employee', 'category'], 'link' => '/personas/solicitudes', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'tickets.replied' => ['variables' => ['number', 'subject', 'employee', 'category'], 'link' => '/personas/solicitudes', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'tickets.updated' => ['variables' => ['number', 'subject', 'status'], 'link' => '/mi/solicitudes', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'limits.reached' => ['variables' => ['metric', 'current', 'limit', 'percent'], 'link' => '/configuracion/consumo', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
    ],

    /*
     * NOT-5 · Mensajes directos por SMS/WhatsApp que envían los módulos por App\Shared\Messaging\MessageSender
     * (no son avisos del centro de notificaciones ni tienen preferencias: siempre se envían).
     * Sus plantillas de WhatsApp requieren aprobación del proveedor; sin ella el mensaje sale por SMS.
     */
    'message_channels' => ['sms', 'whatsapp'],
    'messages' => [
        'identity.login_code' => ['variables' => ['code', 'minutes']],
        'identity.activation_code' => ['variables' => ['code', 'hours']],
        // RN-NOT-07: aviso corto a quien no tiene correo (título y enlace, sin datos sensibles).
        'notifications.notice' => ['variables' => ['title']],
        // COL-8 · RN-COL-11: aviso al celular del titular por un canal distinto al de la solicitud.
        'people.bank_account_requested' => ['variables' => ['last4']],
        'people.bank_account_changed' => ['variables' => ['last4']],
    ],

    // Variables comunes disponibles en todas las plantillas.
    'common_variables' => ['name', 'organization', 'link'],
];
