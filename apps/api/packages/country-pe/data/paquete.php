<?php

/**
 * IMP-4 · Paquete de configuración inicial del Perú. Declara qué crea en la organización al aplicarlo;
 * cada parte la siembra su módulo dueño (App\Shared\Setup\CountryPackComponent) desde los archivos de este
 * mismo paquete (tipos_contrato.php, tipos_ausencia.php, feriados.php, plantillas_documentos.php,
 * vacaciones.php, catálogo maestro de motivos de cese) y desde los roles de sistema.
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
    'version' => '1.0.0',
    'released_on' => '2026-11-24',
    'components' => ['contract_types', 'absence_types', 'holidays', 'document_templates', 'vacation_policies', 'termination_reasons', 'roles'],
    'changelog' => [
        '1.0.0' => 'Primera versión: tipos de contrato, tipos de ausencia y licencias de ley, feriados nacionales, certificado y constancia de trabajo, políticas de vacaciones por régimen, motivos de cese (Tabla 17) y roles de sistema.',
    ],
    'por_validar' => [
        'Redacción del certificado y de la constancia de trabajo (asesoría legal).',
        'Días de vacaciones del régimen general: se toman del parámetro legal vacaciones_dias.',
    ],
];
