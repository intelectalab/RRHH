<?php

/**
 * AUS-4 · Licencias de ley del paquete Perú: ampliaciones por causal y documentos que se piden.
 *
 * Ningún número vive aquí: la duración base sale del parámetro del tipo (absence_types.legal_duration_parameter_code)
 * y cada causal apunta a su parámetro legal con vigencia. Mientras el parámetro no tenga valor verificado la
 * duración se propone como «por validar» y no bloquea (RN-AUS-04).
 *
 * mode:
 *  - add: los días del parámetro se suman a la duración base (maternidad: parto múltiple, hijo con discapacidad).
 *  - total: el parámetro es la duración completa y reemplaza a la base si es mayor (paternidad: parto prematuro
 *    o múltiple, enfermedad grave o complicaciones).
 *
 * documents: claves de lang/{es,en,pt}/absences.php → legal_leave.documents.
 * Las claves de tipo son los códigos de packages/country-pe/data/tipos_ausencia.php.
 */
return [
    'licencia_maternidad' => [
        'extensions' => [
            ['code' => 'multiple_birth', 'parameter' => 'licencia_maternidad_dias_adicionales_parto_multiple', 'mode' => 'add'],
            ['code' => 'child_disability', 'parameter' => 'licencia_maternidad_dias_adicionales_discapacidad', 'mode' => 'add'],
        ],
        'documents' => ['maternity_certificate', 'birth_certificate'],
    ],
    'licencia_paternidad' => [
        'extensions' => [
            ['code' => 'premature_or_multiple', 'parameter' => 'licencia_paternidad_dias_prematuro_multiple', 'mode' => 'total'],
            ['code' => 'serious_condition', 'parameter' => 'licencia_paternidad_dias_complicaciones', 'mode' => 'total'],
        ],
        'documents' => ['birth_certificate'],
    ],
    'licencia_fallecimiento' => [
        'extensions' => [],
        'documents' => ['death_certificate', 'kinship_proof'],
    ],
    'descanso_medico' => [
        'extensions' => [],
        'documents' => ['medical_certificate'],
    ],
];
