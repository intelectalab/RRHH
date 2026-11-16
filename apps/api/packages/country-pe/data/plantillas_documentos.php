<?php

/**
 * Paquete de país Perú · Plantillas del sistema para generar documentos desde la ficha (DOC-4, DOC-5): el
 * certificado de trabajo y las constancias más pedidas. Se crean publicadas en cada organización la primera vez
 * que abre sus plantillas; la organización las puede editar (versión nueva) o retirar.
 *
 * Variables {{grupo.variable}} del catálogo seguro (TemplateVariables) y bloques {{#if}}…{{else}}…{{/if}}.
 * Aquí no hay números legales ni montos: el sueldo solo aparece si una plantilla propia usa la variable
 * compensacion.sueldo_basico, que exige el permiso del campo (RN-DOC-10).
 * Por validar con asesoría legal: redacción del certificado de trabajo.
 *
 * code => [nombre, tipo de plantilla, tipo documental (document_categories.code), cuerpo].
 */
return [
    'certificado_trabajo' => [
        'name' => 'Certificado de trabajo',
        'kind' => 'certificate',
        'category' => 'work_certificate',
        'body' => <<<'TXT'
CERTIFICADO DE TRABAJO

{{empresa.razon_social}}, con RUC {{empresa.ruc}}, con domicilio en {{empresa.direccion}}, certifica que:

{{empleado.nombre_completo}}, identificado(a) con {{empleado.documento_tipo}}, {{#if empleado.fecha_cese}}laboró en nuestra empresa desde el {{empleado.fecha_ingreso}} hasta el {{empleado.fecha_cese}}{{else}}labora en nuestra empresa desde el {{empleado.fecha_ingreso}} a la fecha{{/if}}, desempeñando el cargo de {{empleado.cargo}} en el área de {{empleado.area}}.

Se expide el presente certificado a solicitud del interesado(a), para los fines que estime conveniente.

Lima, {{fecha.hoy_texto}}.


{{empresa.representante_legal}}
Representante legal
{{empresa.razon_social}}
TXT,
    ],
    'constancia_trabajo' => [
        'name' => 'Constancia de trabajo vigente',
        'kind' => 'letter',
        'category' => 'work_certificate',
        'body' => <<<'TXT'
CONSTANCIA DE TRABAJO

{{empresa.razon_social}}, con RUC {{empresa.ruc}}, hace constar que {{empleado.nombre_completo}} trabaja en la empresa desde el {{empleado.fecha_ingreso}}, con el código {{empleado.codigo}}, en el cargo de {{empleado.cargo}}{{#if empleado.sede}}, en {{empleado.sede}}{{/if}}.

{{#if contrato.tipo}}Su contrato vigente es de tipo «{{contrato.tipo}}»{{#if contrato.fin}}, con vencimiento el {{contrato.fin}}{{/if}}.{{/if}}

Se expide la presente a solicitud del interesado(a).

Lima, {{fecha.hoy_texto}}.


Recursos Humanos
{{empresa.razon_social}}
TXT,
    ],
];
