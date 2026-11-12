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
    ],

    // Variables comunes disponibles en todas las plantillas.
    'common_variables' => ['name', 'organization', 'link'],
];
