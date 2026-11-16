<?php

/**
 * Paquete de país Perú · Vacaciones (VAC-1, VAC-2). Reglas de cálculo que dependen del país; los
 * números legales (días por régimen, plazo de goce, récord, venta, fraccionamiento, adelanto) están en
 * los parámetros legales con vigencia, no aquí.
 *
 * - ancla: desde qué fecha corren los años vacacionales de una relación. Cada relación laboral es un
 *   vínculo nuevo (un reingreso empieza su propio récord; lo anterior se liquidó al cesar), salvo que
 *   se reconozca antigüedad en la relación (seniority_date, p. ej. por sustitución de empleador).
 * - parametros: códigos de los parámetros legales que lee el módulo.
 * - por_validar: reglas del paquete que falta confirmar con la norma.
 */
return [
    'ancla' => 'seniority_date_or_hire_date',
    'parametros' => [
        'dias_por_anio' => 'vacaciones_dias',
        'plazo_goce_meses' => 'vacaciones_plazo_goce_meses',
        'record_jornada_6' => 'vacaciones_record_dias_jornada_6',
        'record_jornada_5' => 'vacaciones_record_dias_jornada_5',
        'venta_max_dias' => 'vacaciones_venta_max_dias',
        'fraccion_minima_dias' => 'vacaciones_fraccion_minima_dias',
        'adelanto_max_dias' => 'vacaciones_adelanto_max_dias',
    ],
    // IMP-4 · Políticas que crea el paquete de país: una por régimen, sin días propios (rige la ley del régimen,
    // RN-VAC-01). La organización las ajusta después en Configuración → Políticas de vacaciones.
    'politicas' => [
        ['code' => 'pe_general', 'name' => 'Vacaciones · Régimen general', 'regimen' => 'pe_general'],
        ['code' => 'pe_pequena', 'name' => 'Vacaciones · Pequeña empresa', 'regimen' => 'pe_pequena'],
        ['code' => 'pe_micro', 'name' => 'Vacaciones · Microempresa', 'regimen' => 'pe_micro'],
    ],
    'por_validar' => [
        'ancla' => 'Confirmar si un reingreso dentro del mismo año conserva el récord del vínculo anterior.',
        'record' => 'El récord se verifica con la asistencia (faltas, licencias sin goce) cuando exista el módulo de asistencia.',
    ],
];
