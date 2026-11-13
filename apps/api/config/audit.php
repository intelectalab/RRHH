<?php

/*
 * Auditoría de negocio (M-AUD).
 *
 * entities: tipo de entidad auditada => esquema del contrato. Con esto la auditoría sabe qué
 * campos son sensibles (config/sensitive_fields.php): se guardan cifrados y el visor los
 * enmascara a quien no tiene el permiso del campo.
 */
return [
    'entities' => [
        'user' => 'User',
        'role' => 'Role',
        'legal_entity' => 'LegalEntity',
        'person' => 'Person',
        'person_address' => 'PersonAddress',
        'emergency_contact' => 'EmergencyContact',
        'employment' => 'Employment',
        'compensation' => 'Compensation',
        'position' => 'Position',
        'payroll_result' => 'PayrollResult',
        'disciplinary_case' => 'DisciplinaryCase',
        'grievance' => 'Grievance',
        'medical_exam' => 'MedicalExam',
        'union_membership' => 'UnionMembership',
        'loan' => 'Loan',
        'settlement' => 'Settlement',
        'offer' => 'Offer',
    ],

    // Atributos que nunca se copian a la auditoría.
    'ignored_attributes' => ['created_at', 'updated_at', 'lock_version', 'password_hash', 'session_hash', 'token_hash', 'identifier_hash', 'identifier_enc', 'number_enc', 'number_hash', 'remember_token'],

    'mask' => '••••',

    /*
     * AUD-3 / AUD-5 · Retención de los tres registros, cada uno por separado:
     *  - auditoría de negocio: parámetro privacidad.retencion_auditoria_anios (por organización); pasados
     *    hot_months meses se archiva en frío (archive_disk) y se sigue consultando hasta cumplir el plazo;
     *  - actividad de usuarios: parámetro privacidad.retencion_actividad_dias (por organización);
     *  - logs técnicos: config/logging.php (canal daily, LOG_RETENTION_DAYS), los fija la plataforma.
     */
    'hot_months' => (int) env('AUDIT_HOT_MONTHS', 12),
    'archive_disk' => env('AUDIT_ARCHIVE_DISK', env('FILESYSTEM_DISK', 's3')),
    'archive_prefix' => 'audit-archives',
];
