<?php

/**
 * NOM-1 · NOM-2 · Paquete Perú para el motor de nómina (datos, no código del núcleo).
 *
 * ADR 0016: primero régimen general, microempresa, pequeña empresa y modalidades formativas; construcción
 * civil y agrario llegan después (el motor los rechaza como «régimen no soportado» hasta tener estrategia).
 *
 * Cada régimen indica qué parámetro legal da los días de la base mensual para prorratear (RN-NOM-05) y si
 * un mes completo cuenta como esa base aunque tenga otra cantidad de días calendario. Todo «por validar»
 * con el especialista en planilla (ADR 0021).
 *
 * PAR-5 · RN-PAR-04 · «parametros_requeridos»: parámetros legales del régimen sin los cuales no se calcula; si
 * alguno no tiene valor vigente a la fecha del periodo, la relación queda «con error» y se informa cuál falta.
 * Los valores viven en parametros_legales_pe.json (regla 4); aquí solo sus códigos.
 */
return [
    'pais' => 'PE',
    'version' => '1.2.0',
    'decimales' => 2,
    'por_validar' => true,
    // NOM-10 · RN-PE-MYP-02/03: régimen que se propone cuando la empresa no tiene acreditación vigente o la
    // relación es anterior a la acreditación.
    'regimen_por_defecto' => 'pe_general',
    // NOM-9 · Estrategia de cada régimen: qué estrategia de cálculo usa (micro y pequeña usan la general con
    // sus propios parámetros), la unidad de remuneración, los procesos especiales que genera y si percibe
    // gratificaciones. Los montos y tasas viven en los parámetros legales del régimen, no aquí.
    'regimenes' => [
        'pe_general' => ['tipo' => 'general', 'estrategia' => 'general', 'unidad_remuneracion' => 'mensual', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => false,
            'procesos' => ['regular', 'gratification', 'cts', 'profit_sharing', 'settlement', 'special'], 'gratificaciones' => true,
            'parametros_requeridos' => ['dias_base_mes', 'rmv', 'uit', 'vacaciones_dias', 'gratificacion_aplica', 'gratificacion_factor', 'cts_aplica', 'cts_dias_por_anio', 'essalud_aporte_pct', 'indemnizacion_dias_por_anio', 'indemnizacion_tope_dias']],
        'pe_micro' => ['tipo' => 'micro', 'estrategia' => 'general', 'unidad_remuneracion' => 'mensual', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => true,
            'procesos' => ['regular', 'settlement', 'special'], 'gratificaciones' => false,
            'parametros_requeridos' => ['dias_base_mes', 'rmv', 'uit', 'vacaciones_dias', 'gratificacion_aplica', 'cts_aplica', 'indemnizacion_dias_por_anio', 'indemnizacion_tope_dias']],
        'pe_pequena' => ['tipo' => 'small', 'estrategia' => 'general', 'unidad_remuneracion' => 'mensual', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => true,
            'procesos' => ['regular', 'gratification', 'cts', 'profit_sharing', 'settlement', 'special'], 'gratificaciones' => true,
            'parametros_requeridos' => ['dias_base_mes', 'rmv', 'uit', 'vacaciones_dias', 'gratificacion_aplica', 'gratificacion_factor', 'cts_aplica', 'cts_dias_por_anio', 'cts_tope_dias', 'essalud_aporte_pct', 'indemnizacion_dias_por_anio', 'indemnizacion_tope_dias']],
        'pe_formativo' => ['tipo' => 'training', 'estrategia' => 'formativo', 'unidad_remuneracion' => 'mensual', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => false,
            'procesos' => ['regular', 'special'], 'gratificaciones' => false,
            'parametros_requeridos' => ['dias_base_mes', 'rmv', 'uit']],
    ],
];
