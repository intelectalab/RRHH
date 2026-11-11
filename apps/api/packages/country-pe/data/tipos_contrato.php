<?php

/**
 * Paquete de país Perú · Tipos de contrato y modalidades (CON-1).
 *
 * Fuente: TUO del D. Leg. 728 (DS 003-97-TR), título II (contratos sujetos a modalidad, arts. 53–83),
 * art. 4 (contrato indeterminado y a tiempo parcial) y Ley 28518 (modalidades formativas).
 *
 * Aquí no hay números legales: cada tipo apunta al código del parámetro legal (tabla legal_parameters,
 * kit/database/catalogos/parametros_legales_pe.json) que fija su plazo máximo y su periodo de prueba.
 * Los tipos sin parámetro de plazo máximo propio quedan sujetos al tope acumulado
 * (contrato_modalidad_tope_acumulado_anios), que figura «por validar» en kit/docs/19.
 * Por validar: plazos máximos de las modalidades formativas (no constan en kit/docs/19).
 *
 * category: indefinite | fixed_term | part_time | internship | other (contract_types.category).
 */
return [
    'source' => 'DS 003-97-TR; Ley 28518',
    'types' => [
        ['code' => 'indeterminado', 'name' => 'Plazo indeterminado', 'category' => 'indefinite', 'modality_code' => null,
            'requires_end_date' => false, 'requires_objective_cause' => false, 'max_duration' => null, 'trial_period' => 'periodo_prueba_meses'],
        ['code' => 'tiempo_parcial', 'name' => 'Tiempo parcial', 'category' => 'part_time', 'modality_code' => null,
            'requires_end_date' => false, 'requires_objective_cause' => false, 'max_duration' => null, 'trial_period' => 'periodo_prueba_meses'],

        // Contratos de naturaleza temporal (arts. 57–59).
        ['code' => 'inicio_actividad', 'name' => 'Por inicio o incremento de actividad', 'category' => 'fixed_term', 'modality_code' => 'temporal',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => 'contrato_inicio_actividad_max_anios', 'trial_period' => 'periodo_prueba_meses'],
        ['code' => 'necesidad_mercado', 'name' => 'Por necesidades del mercado', 'category' => 'fixed_term', 'modality_code' => 'temporal',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => 'contrato_necesidad_mercado_max_anios', 'trial_period' => 'periodo_prueba_meses'],
        ['code' => 'reconversion_empresarial', 'name' => 'Por reconversión empresarial', 'category' => 'fixed_term', 'modality_code' => 'temporal',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => 'contrato_reconversion_max_anios', 'trial_period' => 'periodo_prueba_meses'],

        // Contratos de naturaleza accidental (arts. 60–62).
        ['code' => 'ocasional', 'name' => 'Ocasional', 'category' => 'fixed_term', 'modality_code' => 'accidental',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => 'contrato_ocasional_max_meses_por_anio', 'trial_period' => 'periodo_prueba_meses'],
        ['code' => 'suplencia', 'name' => 'De suplencia', 'category' => 'fixed_term', 'modality_code' => 'accidental',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => null, 'trial_period' => 'periodo_prueba_meses'],
        ['code' => 'emergencia', 'name' => 'De emergencia', 'category' => 'fixed_term', 'modality_code' => 'accidental',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => null, 'trial_period' => 'periodo_prueba_meses'],

        // Contratos de obra o servicio (arts. 63–71).
        ['code' => 'obra_servicio', 'name' => 'Para obra determinada o servicio específico', 'category' => 'fixed_term', 'modality_code' => 'obra_servicio',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => null, 'trial_period' => 'periodo_prueba_meses'],
        ['code' => 'intermitente', 'name' => 'Intermitente', 'category' => 'fixed_term', 'modality_code' => 'obra_servicio',
            'requires_end_date' => false, 'requires_objective_cause' => true, 'max_duration' => null, 'trial_period' => 'periodo_prueba_meses'],
        ['code' => 'temporada', 'name' => 'De temporada', 'category' => 'fixed_term', 'modality_code' => 'obra_servicio',
            'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration' => null, 'trial_period' => 'periodo_prueba_meses'],

        // Modalidades formativas (Ley 28518): no son contratos de trabajo; se registran como convenios.
        ['code' => 'practicas_preprofesionales', 'name' => 'Convenio de prácticas preprofesionales', 'category' => 'internship', 'modality_code' => 'formativa',
            'requires_end_date' => true, 'requires_objective_cause' => false, 'max_duration' => null, 'trial_period' => null],
        ['code' => 'practicas_profesionales', 'name' => 'Convenio de prácticas profesionales', 'category' => 'internship', 'modality_code' => 'formativa',
            'requires_end_date' => true, 'requires_objective_cause' => false, 'max_duration' => null, 'trial_period' => null],
        ['code' => 'aprendizaje', 'name' => 'Convenio de aprendizaje', 'category' => 'internship', 'modality_code' => 'formativa',
            'requires_end_date' => true, 'requires_objective_cause' => false, 'max_duration' => null, 'trial_period' => null],
    ],
];
