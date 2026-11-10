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
    'ignored_attributes' => ['created_at', 'updated_at', 'lock_version', 'password_hash', 'session_hash', 'token_hash', 'identifier_hash', 'identifier_enc', 'remember_token'],

    'mask' => '••••',
];
