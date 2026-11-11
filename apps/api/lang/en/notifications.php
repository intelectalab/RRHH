<?php

/**
 * Textos por defecto de los avisos (NOT-1). Cada organización puede reemplazarlos en
 * Configuración → Mensajes. Las variables van entre llaves: {name}.
 */
return [
    'templates' => [
        'approval.pending' => [
            'in_app' => ['subject' => 'Request to approve', 'body' => '{requester} sent a {type} request (level {level}).'],
            'email' => ['subject' => 'You have a request to approve', 'body' => "Hi {name},\n\nYou have a {type} request waiting for your decision at {organization}.\n\nReview it here: {link}\n\nFor security, details are only shown after signing in."],
        ],
        'approval.reminder' => [
            'in_app' => ['subject' => 'Reminder: request to approve', 'body' => '{requester}\'s {type} request is still waiting for your decision.'],
            'email' => ['subject' => 'Reminder: you have a request to approve', 'body' => "Hi {name},\n\nA {type} request is still waiting for your decision at {organization}.\n\nReview it here: {link}"],
        ],
        'approval.escalated' => [
            'in_app' => ['subject' => 'Request escalated to you', 'body' => '{requester}\'s {type} request is overdue and now needs your decision.'],
            'email' => ['subject' => 'An overdue request was assigned to you', 'body' => "Hi {name},\n\nA {type} request is overdue and now needs your decision at {organization}.\n\nReview it here: {link}"],
        ],
        'approval.approved' => [
            'in_app' => ['subject' => 'Your request was approved', 'body' => 'Your {type} request was approved.'],
            'email' => ['subject' => 'Your request was approved', 'body' => "Hi {name},\n\nYour {type} request was approved.\n\nSee the details here: {link}"],
        ],
        'approval.rejected' => [
            'in_app' => ['subject' => 'Your request was rejected', 'body' => 'Your {type} request was rejected: “{reason}”.'],
            'email' => ['subject' => 'Your request was rejected', 'body' => "Hi {name},\n\nYour {type} request was rejected. The reason is in the app.\n\nSee it here: {link}"],
        ],
        'task.assigned' => [
            'in_app' => ['subject' => 'New task assigned', 'body' => '{title} · due {due_on}'],
            'email' => ['subject' => 'You have a new task', 'body' => "Hi {name},\n\nYou were assigned a task at {organization}: {title}.\n\nOpen it here: {link}"],
        ],
        'task.overdue' => [
            'in_app' => ['subject' => 'Overdue task', 'body' => '{title} was due on {due_on}.'],
            'email' => ['subject' => 'You have an overdue task', 'body' => "Hi {name},\n\nThe task “{title}” was due on {due_on}.\n\nOpen it here: {link}"],
        ],
        'automation.notice' => [
            'in_app' => ['subject' => '{title}', 'body' => '{message}'],
            'email' => ['subject' => '{title}', 'body' => "Hi {name},\n\n{message}\n\nMore details here: {link}"],
        ],
        'automation.paused' => [
            'in_app' => ['subject' => 'An automation paused itself', 'body' => 'The rule “{rule}” was paused because it ran too many times in a row. Review it before turning it back on.'],
            'email' => ['subject' => 'An automation paused itself', 'body' => "Hi {name},\n\nThe automation rule “{rule}” paused itself because it ran too many times in one hour (possible loop). Review it before turning it back on.\n\nOpen it here: {link}"],
        ],
        'account.security' => [
            'in_app' => ['subject' => 'Account security notice', 'body' => '{event}'],
            'email' => ['subject' => 'Account security notice', 'body' => "Hi {name},\n\n{event}\n\nIf this was not you, contact HR right away."],
        ],
    ],
    'subject_types' => [
        'vacation_request' => 'vacation',
        'permission_request' => 'leave',
        'overtime_request' => 'overtime',
        'expense_report' => 'expenses',
        'job_requisition' => 'job requisition',
        'position_request' => 'new position',
        'compensation_change' => 'compensation change',
        'other' => 'request',
    ],
    'invalid_variables' => 'Variables not allowed: :vars. Use only: :allowed.',
    'unknown_category' => 'Unknown notification type.',
    'mandatory_in_app' => 'This notification is mandatory and cannot be turned off in the app.',
    'template_exists' => 'A template for that notification, channel and language already exists.',
    'unsupported_channel' => 'That channel is not available yet.',
];
