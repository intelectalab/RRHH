<?php

/**
 * IMP-4 · Paquete de configuración inicial del Perú. Declara qué crea en la organización al aplicarlo;
 * cada parte la siembra su módulo dueño (App\Shared\Setup\CountryPackComponent) desde los archivos de este
 * mismo paquete (tipos_contrato.php, tipos_ausencia.php, feriados.php, plantillas_documentos.php,
 * vacaciones.php, conceptos_pe.json, catálogo maestro de motivos de cese) y desde los roles de sistema.
 *
 * Aplicar es idempotente: solo crea lo que falta y no pisa lo que la organización ya editó. Lo creado se
 * edita después en la pantalla de cada módulo. Ningún número legal vive aquí: plazos, días y topes salen de
 * los parámetros legales con vigencia.
 *
 * Al cambiar el contenido se sube la versión (semver) y se agrega su nota al historial; la organización
 * ve qué versión tiene instalada y puede volver a aplicar para recibir lo nuevo.
 */
return [
    'code' => 'pe-base',
    'version' => '1.4.0',
    'released_on' => '2026-12-02',
    'components' => ['contract_types', 'absence_types', 'holidays', 'document_templates', 'vacation_policies', 'termination_reasons', 'roles', 'payroll_concepts'],
    'changelog' => [
        '1.4.0' => 'Beneficios sociales (BSO-1 a BSO-3): gratificación con bonificación extraordinaria por meses completos del semestre y factor del régimen, CTS semestral con la fracción de la última gratificación y la proporción del régimen, y participación en utilidades desde la distribución aprobada. Cada concepto declara en qué procesos corre (los del mes no entran a la gratificación, la CTS ni las utilidades). Los conceptos ya instalados no cambian: la organización publica la nueva versión desde Conceptos.',
        '1.3.0' => 'Asignación familiar solo con hijos con sustento vigente y en los regímenes que corresponde (BSO-4); remuneración vacacional, venta de días e indemnización por vacaciones no gozadas calculadas desde el libro de vacaciones, y básica sin los días de descanso vacacional (BSO-5). Los conceptos ya instalados no cambian: la organización publica la nueva versión desde Conceptos.',
        '1.2.0' => 'Aportes (PER-6): comisión de AFP según el tipo (flujo o mixta), prima con el tope de la remuneración asegurable, EsSalud salvo para afiliados al SIS y crédito por EPS. Los conceptos ya instalados no cambian: la organización publica la nueva versión desde Conceptos.',
        '1.1.0' => 'Conceptos de planilla (CPT-5) desde conceptos_pe.json: fórmula en el lenguaje del motor donde ya se calcula, afectaciones por base y código de la Tabla 22. Se aplican solo con el módulo de planilla contratado.',
        '1.0.0' => 'Primera versión: tipos de contrato, tipos de ausencia y licencias de ley, feriados nacionales, certificado y constancia de trabajo, políticas de vacaciones por régimen, motivos de cese (Tabla 17) y roles de sistema.',
    ],
    'por_validar' => [
        'Afectaciones, fórmulas y códigos de la Tabla 22 de los conceptos de planilla (especialista en planillas, ADR 0021).',
        'Redacción del certificado y de la constancia de trabajo (asesoría legal).',
        'Días de vacaciones del régimen general: se toman del parámetro legal vacaciones_dias.',
        'Remuneración computable de gratificación y CTS con promedio de remuneraciones variables regulares (RN-BSO-12) y tope individual de utilidades (especialista en planillas).',
    ],
];
