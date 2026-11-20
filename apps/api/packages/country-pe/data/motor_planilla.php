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
    'version' => '1.0.0',
    'decimales' => 2,
    'por_validar' => true,
    'regimenes' => [
        'pe_general' => ['tipo' => 'general', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => false],
        'pe_micro' => ['tipo' => 'micro', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => true],
        'pe_pequena' => ['tipo' => 'small', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => true],
        'pe_formativo' => ['tipo' => 'training', 'base_prorrateo' => 'dias_base_mes', 'mes_completo_igual_base' => true, 'requiere_acreditacion' => false],
    ],
];
