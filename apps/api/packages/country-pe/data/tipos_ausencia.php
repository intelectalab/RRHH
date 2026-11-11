<?php

/**
 * AUS-1 · Tipos de ausencia que el paquete Perú precarga en cada organización (kit/docs/modulos/M-AUS.md §2).
 *
 * Ningún número legal vive aquí: la duración de las licencias de ley sale de legal_parameters
 * (legal_duration_parameter_code) y queda «por validar» mientras el parámetro no tenga valor verificado.
 * Las reglas son el punto de partida: la organización las ajusta con vigencia (topes, sustento,
 * quién solicita, flujo de aprobación, alcance por empresa o régimen).
 *
 * payroll_concept_code apunta a kit/database/catalogos/conceptos_pe.json. Las licencias sin goce
 * descuentan (is_paid = false) y su concepto de descuento lo define la planilla (CPT); queda vacío.
 */
return [
    'types' => [
        [
            'code' => 'descanso_medico', 'name' => 'Descanso médico', 'category' => 'medical_leave', 'is_confidential' => true, 'legal_duration' => null,
            'rules' => ['is_paid' => true, 'unit' => 'days', 'requires_document' => true, 'requestable_by' => 'employee', 'subsidy_kind' => 'illness', 'payroll_concept_code' => 'subsidio_incapacidad'],
        ],
        [
            'code' => 'licencia_maternidad', 'name' => 'Licencia por maternidad', 'category' => 'maternity', 'is_confidential' => true, 'legal_duration' => 'licencia_maternidad_dias',
            'rules' => ['is_paid' => true, 'unit' => 'days', 'max_period' => 'event', 'requires_document' => true, 'requestable_by' => 'hr', 'subsidy_kind' => 'maternity', 'payroll_concept_code' => 'subsidio_maternidad'],
        ],
        [
            'code' => 'licencia_paternidad', 'name' => 'Licencia por paternidad', 'category' => 'paternity', 'is_confidential' => false, 'legal_duration' => 'licencia_paternidad_dias',
            'rules' => ['is_paid' => true, 'unit' => 'days', 'max_period' => 'event', 'requires_document' => true, 'requestable_by' => 'employee'],
        ],
        [
            'code' => 'licencia_fallecimiento', 'name' => 'Licencia por fallecimiento de un familiar', 'category' => 'bereavement', 'is_confidential' => false, 'legal_duration' => 'licencia_fallecimiento_familiar_dias',
            'rules' => ['is_paid' => true, 'unit' => 'days', 'max_period' => 'event', 'requires_document' => true, 'requestable_by' => 'employee'],
        ],
        [
            'code' => 'licencia_sindical', 'name' => 'Licencia sindical', 'category' => 'union', 'is_confidential' => false, 'legal_duration' => null,
            'rules' => ['is_paid' => true, 'unit' => 'days', 'requires_document' => true, 'requestable_by' => 'hr'],
        ],
        [
            'code' => 'permiso_personal', 'name' => 'Permiso personal', 'category' => 'personal', 'is_confidential' => false, 'legal_duration' => null,
            'rules' => ['is_paid' => true, 'unit' => 'hours', 'requires_document' => false, 'requestable_by' => 'employee'],
        ],
        [
            'code' => 'licencia_sin_goce', 'name' => 'Licencia sin goce de haber', 'category' => 'unpaid', 'is_confidential' => false, 'legal_duration' => null,
            'rules' => ['is_paid' => false, 'unit' => 'days', 'requires_document' => false, 'requestable_by' => 'employee', 'counts_as_worked_for_benefits' => false],
        ],
        [
            'code' => 'compensacion', 'name' => 'Día de compensación', 'category' => 'compensatory', 'is_confidential' => false, 'legal_duration' => null,
            'rules' => ['is_paid' => true, 'unit' => 'days', 'requires_document' => false, 'requestable_by' => 'employee'],
        ],
        [
            'code' => 'trabajo_remoto', 'name' => 'Trabajo remoto ocasional', 'category' => 'remote_work', 'is_confidential' => false, 'legal_duration' => null,
            // RN-AUS-09: no descuenta ni afecta asistencia.
            'rules' => ['is_paid' => true, 'affects_attendance' => false, 'unit' => 'days', 'requires_document' => false, 'requestable_by' => 'employee'],
        ],
        [
            'code' => 'suspension', 'name' => 'Suspensión', 'category' => 'suspension', 'is_confidential' => false, 'legal_duration' => null,
            'rules' => ['is_paid' => false, 'unit' => 'days', 'requires_document' => true, 'requestable_by' => 'hr', 'counts_as_worked_for_benefits' => false],
        ],
    ],
];
