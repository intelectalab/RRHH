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
    'version' => '1.3.0',
    'decimales' => 2,
    'por_validar' => true,
    // NOM-10 · RN-PE-MYP-02/03: régimen que se propone cuando la empresa no tiene acreditación vigente o la
    // relación es anterior a la acreditación.
    'regimen_por_defecto' => 'pe_general',
    // NOM-9 · Estrategia de cada régimen: qué estrategia de cálculo usa (micro y pequeña usan la general con
    // sus propios parámetros), la unidad de remuneración, los procesos especiales que genera y si percibe
    // gratificaciones. Los montos y tasas viven en los parámetros legales del régimen, no aquí.
    // PER-5 · Impuesto a la renta del trabajador (quinta categoría, kit/docs/18 §2): qué concepto recibe la
    // retención, sobre qué base de afectación, con qué parámetros legales (valores en parametros_legales_pe.json)
    // y qué gratificaciones ordinarias se proyectan (mes de pago y semestre que paga; calendario, no cifras legales).
    'impuesto_renta' => [
        'concepto' => 'renta_quinta',
        'base' => 'quinta',
        'procesos' => ['regular', 'settlement'],
        'proceso_ajuste_final' => 'settlement',
        'parametros' => ['uit' => 'uit', 'deduccion_uit' => 'quinta_deduccion_uit', 'escala' => 'quinta_escala', 'divisores' => 'quinta_divisores',
            'cortes' => 'quinta_cortes_retenciones', 'tasa_no_domiciliado' => 'quinta_no_domiciliado_tasa_pct'],
        'gratificaciones' => [
            'aplica' => 'gratificacion_aplica', 'factor' => 'gratificacion_factor',
            'bonificacion_pct' => 'gratificacion_bonificacion_extraordinaria_pct', 'bonificacion_eps_pct' => 'gratificacion_bonificacion_extraordinaria_eps_pct',
            'proceso' => 'gratification', 'meses_semestre' => 6,
            'pagos' => [['mes_pago' => 7, 'desde_mes' => 1, 'hasta_mes' => 6], ['mes_pago' => 12, 'desde_mes' => 7, 'hasta_mes' => 12]],
        ],
    ],
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
