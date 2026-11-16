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
    // Canales que hoy tienen adaptador. Push, SMS y WhatsApp se suman con NOT-4 y NOT-5.
    'channels' => ['in_app', 'email'],

    'categories' => [
        'approval.pending' => ['variables' => ['requester', 'type', 'level'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.reminder' => ['variables' => ['requester', 'type'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.escalated' => ['variables' => ['requester', 'type'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.approved' => ['variables' => ['type'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'approval.rejected' => ['variables' => ['type', 'reason'], 'link' => '/aprobaciones?id={approval_request_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'task.assigned' => ['variables' => ['title', 'due_on'], 'link' => '/tareas?id={task_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'task.overdue' => ['variables' => ['title', 'due_on'], 'link' => '/tareas?id={task_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'automation.notice' => ['variables' => ['title', 'message'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'automation.paused' => ['variables' => ['rule'], 'link' => '/configuracion/automatizaciones', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => true],
        'account.security' => ['variables' => ['event'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.suspended' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.reactivated' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.activated' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'organization.cancelled' => ['variables' => ['reason', 'retention_until'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        // NOT-3: la dirección de correo de una persona rebotó; RRHH la corrige o la reactiva.
        'notifications.email_bounced' => ['variables' => ['user', 'reason'], 'link' => '/configuracion/mensajes?vista=entregas&estado=bounced', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // SAS-6 · RN-SAS-02 · RN-SAS-03: el consumo se acerca al tope del plan o llega a él.
        'limits.warning' => ['variables' => ['metric', 'current', 'limit', 'percent'], 'link' => '/configuracion/consumo', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // COL-6: un hijo derechohabiente cumplió la mayoría de edad; RRHH lo revisa en la ficha.
        'people.dependent_majority' => ['variables' => ['employee'], 'link' => '/personas/colaboradores/{employment_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // COL-8 · RN-COL-11: se registró o cambió la cuenta de pago del titular (solo los últimos dígitos).
        'people.bank_account_requested' => ['variables' => ['last4'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        'people.bank_account_changed' => ['variables' => ['last4'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
        // CON-2: el periodo de prueba de un colaborador termina pronto; el jefe y RRHH lo revisan en la ficha.
        'contracts.probation_ending' => ['variables' => ['employee', 'ends_on'], 'link' => '/personas/colaboradores/{employment_id}', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // CON-4 · RN-CON-04: un contrato vence pronto (con el acumulado frente al tope) o el acumulado se acerca al tope.
        'contracts.expiring' => ['variables' => ['employee', 'ends_on', 'days_left', 'explanation'], 'link' => '/personas/contratos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'contracts.cap_approaching' => ['variables' => ['employee', 'ends_on', 'explanation'], 'link' => '/personas/contratos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // AUS-3: un descanso pasó a subsidio; quien gestiona subsidios presenta el reembolso (sin datos de salud).
        'absences.subsidy_started' => ['variables' => ['employee', 'days', 'starts_on'], 'link' => '/tiempo/subsidios', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // DOC-2 · RN-DOC-09: un documento vence pronto o venció (al responsable y al titular); ME-12: uno entregado fue rechazado.
        'documents.expiring' => ['variables' => ['document', 'type', 'employee', 'expires_on', 'days_left'], 'link' => '/documentos/vencimientos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'documents.expired' => ['variables' => ['document', 'type', 'employee', 'expires_on'], 'link' => '/documentos/vencimientos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        'documents.rejected' => ['variables' => ['document', 'type', 'reason'], 'link' => '/documentos/vencimientos', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
        // DOC-3 · Documento solicitado al colaborador: lo entrega desde su portal.
        'documents.requested' => ['variables' => ['document', 'due_on', 'instructions'], 'link' => '/mi', 'channels' => ['in_app', 'email'], 'mandatory' => false, 'urgent' => false],
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
