<?php

/**
 * Tipos de trámite que piden aprobación. Cada módulo registra el suyo al implementarse.
 *  - bulk_safe: se puede aprobar en masa desde el centro de aprobaciones (RN-WFL-12).
 * Un tipo que no figura aquí no admite aprobación masiva.
 */
return [
    'subject_types' => [
        'vacation_request' => ['bulk_safe' => true],
        'permission_request' => ['bulk_safe' => true],
        'overtime_request' => ['bulk_safe' => true],
        'expense_report' => ['bulk_safe' => false],
        'job_requisition' => ['bulk_safe' => false],
        'position_request' => ['bulk_safe' => false],
        'compensation_change' => ['bulk_safe' => false],
    ],
];
