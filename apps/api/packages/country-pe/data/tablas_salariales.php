<?php

/**
 * PAR-6 · RN-PAR-10 · RN-PAR-11 · Tablas salariales por convenio: cada tabla es un lote de parámetros legales del
 * régimen con la misma vigencia y la misma fuente (el acta del convenio). Los valores viven en
 * parametros_legales_pe.json y se publican desde el paquete de país; ninguna organización los teclea. Cada
 * organización verifica el lote contra el acta antes de usarlo como definitivo.
 *
 * El parámetro «ancla» marca la vigencia del lote (su valid_from y valid_to); los demás deben tener la misma.
 * Estructura «por validar» con el especialista en planilla (ADR 0021).
 */
return [
    'por_validar' => true,
    'tablas' => [
        'pe_construccion_civil' => [
            'nombre' => 'Tabla salarial de construcción civil',
            'regimen' => 'pe_construccion',
            'ancla' => 'cc_jornal_basico',
            'parametros' => [
                'cc_jornal_basico', 'cc_buc_pct', 'cc_movilidad_dia', 'cc_gratificacion_jornales', 'cc_gratificacion_fracciones',
                'cc_vacaciones_pct', 'cc_compensacion_pct', 'cc_asignacion_escolar_jornales_anio', 'cc_altura_pct',
                'cc_contacto_agua_pct', 'cc_altas_temperaturas_dia', 'cc_feriado_bae_pct', 'cc_he_tasa_1_pct', 'cc_he_tasa_2_pct',
                'cc_conafovicer_pct',
            ],
        ],
    ],
];
