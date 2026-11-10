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
        'account.security' => ['variables' => ['event'], 'link' => '/', 'channels' => ['in_app', 'email'], 'mandatory' => true, 'urgent' => true],
    ],

    // Variables comunes disponibles en todas las plantillas.
    'common_variables' => ['name', 'organization', 'link'],
];
