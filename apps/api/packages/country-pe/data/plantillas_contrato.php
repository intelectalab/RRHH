<?php

/**
 * Paquete de país Perú · Plantillas de texto por defecto para generar el contrato, su renovación y la
 * adenda (CON-2, CON-3) cuando el tipo de contrato no tiene una plantilla propia de la organización
 * (contract_types.document_template_id). Variables entre llaves; las que no tengan valor se muestran «—».
 *
 * Aquí no hay números legales: plazos, topes y periodo de prueba vienen del registro del contrato, que se
 * valida contra los parámetros legales con vigencia. La remuneración no se escribe en el documento
 * generado (es un dato sensible con permiso por campo); se consigna en el anexo que firma RRHH.
 * Por validar con asesoría legal: redacción de las cláusulas.
 */
return [
    'contract' => [
        'title' => 'CONTRATO DE TRABAJO · {contract_type}',
        'body' => <<<'TXT'
Conste por el presente documento el contrato de trabajo que celebran, de una parte, {employer}, con RUC {employer_tax_id}, en adelante EL EMPLEADOR; y de la otra, {employee}, identificado(a) con {employee_document}, en adelante EL TRABAJADOR, en los términos siguientes:

PRIMERA · OBJETO. EL EMPLEADOR contrata a EL TRABAJADOR para desempeñar el puesto de {job}, en la modalidad «{contract_type}».

SEGUNDA · CAUSA OBJETIVA. {objective_cause}

TERCERA · PLAZO. El contrato rige desde el {starts_on} hasta el {ends_on}.

CUARTA · PERIODO DE PRUEBA. El periodo de prueba concluye el {trial_period_ends_on}.

QUINTA · JORNADA Y LUGAR. Jornada semanal de {weekly_hours} horas, en modalidad {work_modality}.

SEXTA · REMUNERACIÓN. La remuneración pactada y su forma de pago constan en el anexo de condiciones económicas, que forma parte de este contrato.

SÉPTIMA · OBLIGACIONES. Las partes cumplirán las obligaciones que establecen la ley, el reglamento interno de trabajo y las políticas de EL EMPLEADOR.

Firmado en dos ejemplares de igual valor.

Contrato N.° {number}



______________________________                ______________________________
EL EMPLEADOR                                                    EL TRABAJADOR
TXT,
    ],
    'renewal' => [
        'title' => 'PRÓRROGA DEL CONTRATO DE TRABAJO · {contract_type}',
        'body' => <<<'TXT'
{employer}, con RUC {employer_tax_id} (EL EMPLEADOR), y {employee}, identificado(a) con {employee_document} (EL TRABAJADOR), acuerdan prorrogar el contrato N.° {previous_number} en los términos siguientes:

PRIMERA · PLAZO. La prórroga rige desde el {starts_on} hasta el {ends_on}.

SEGUNDA · CAUSA OBJETIVA. {objective_cause}

TERCERA · CONDICIONES. Se mantienen las demás condiciones del contrato prorrogado, salvo las que se modifiquen por adenda.

Firmado en dos ejemplares de igual valor.

Prórroga N.° {number}



______________________________                ______________________________
EL EMPLEADOR                                                    EL TRABAJADOR
TXT,
    ],
    'amendment' => [
        'title' => 'ADENDA AL CONTRATO DE TRABAJO N.° {contract_number}',
        'body' => <<<'TXT'
{employer}, con RUC {employer_tax_id} (EL EMPLEADOR), y {employee}, identificado(a) con {employee_document} (EL TRABAJADOR), acuerdan modificar el contrato N.° {contract_number} como sigue:

PRIMERA · MATERIA. {kind}.

SEGUNDA · DETALLE. {description}

TERCERA · VIGENCIA. La modificación rige desde el {effective_on}. Las demás condiciones del contrato se mantienen.

Firmado en dos ejemplares de igual valor.

Adenda N.° {number}



______________________________                ______________________________
EL EMPLEADOR                                                    EL TRABAJADOR
TXT,
    ],
];
