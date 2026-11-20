<?php

/**
 * PAR-1 · RN-PAR-07 · Parámetros legales en los que la organización puede registrar un valor propio porque la
 * norma lo permite, y en qué dirección es «más favorable» para el trabajador: 'mayor' (solo se acepta un valor
 * igual o superior al legal) o 'menor'. Un parámetro que no figura aquí no admite valor propio.
 *
 * Lista «por validar» con el especialista en planilla (ADR 0021); no lleva números, solo códigos.
 */
return [
    'por_validar' => true,
    'parametros' => [
        'vacaciones_dias' => 'mayor',
        'cts_dias_por_anio' => 'mayor',
        'asignacion_familiar_pct_rmv' => 'mayor',
        'gratificacion_factor' => 'mayor',
        'indemnizacion_dias_por_anio' => 'mayor',
        'indemnizacion_tope_dias' => 'mayor',
        'descanso_medico_dias_cargo_empleador' => 'mayor',
        'licencia_paternidad_dias' => 'mayor',
        'licencia_fallecimiento_familiar_dias' => 'mayor',
        'eps_credito_pct' => 'menor',
    ],
];
