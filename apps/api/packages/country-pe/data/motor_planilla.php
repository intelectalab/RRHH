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
 */
return [
    'pais' => 'PE',
    'version' => '1.1.0',
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
            'procesos' => ['regular', 'gratification', 'cts', 'profit_sharing', 'settlement', 'special'], 'gratificaciones' => true],
        'pe_micro' => ['tipo' => 'micro', 'estrategia' => 'general', 'unidad_remuneracion' => 'mensual', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => true,
            'procesos' => ['regular', 'settlement', 'special'], 'gratificaciones' => false],
        'pe_pequena' => ['tipo' => 'small', 'estrategia' => 'general', 'unidad_remuneracion' => 'mensual', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => true,
            'procesos' => ['regular', 'gratification', 'cts', 'profit_sharing', 'settlement', 'special'], 'gratificaciones' => true],
        'pe_formativo' => ['tipo' => 'training', 'estrategia' => 'formativo', 'unidad_remuneracion' => 'mensual', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => false,
            'procesos' => ['regular', 'special'], 'gratificaciones' => false],
    ],
];
