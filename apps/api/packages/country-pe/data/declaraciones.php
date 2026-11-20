<?php

/**
 * Paquete de país Perú · Códigos que usan los archivos de declaración (DEC-2, DEC-3, DEC-4). Son datos del paquete,
 * no reglas en código: cambian con la norma o con la herramienta oficial y se actualizan aquí.
 *
 * - documento_tabla3: tipo de documento de la persona (catálogo propio) → código de la Tabla 3 de SUNAT.
 * - documento_afpnet: tipo de documento → código de AFPnet (0 DNI, 1 CE, 4 pasaporte; el resto por confirmar).
 * - sexo_tabla: sexo de la persona → código de T-Registro.
 * - excepcion_afpnet: motivo por el que un trabajador con relación laboral no aporta en el mes (sin remuneración
 *   asegurable): licencia sin goce, subsidio u otro motivo.
 * - tipo_trabajo_afpnet: régimen laboral → tipo de trabajo de AFPnet (C construcción; el resto N).
 * - conciliacion_afpnet: conceptos propios cuyo total se compara con la liquidación de AFPnet.
 * - tolerancia_conciliacion: diferencia admitida (en la moneda de la planilla) entre el cálculo propio y la
 *   liquidación de AFPnet; la organización puede ajustarla con el parámetro declaraciones.afpnet_tolerancia.
 *
 * Por validar: todos los códigos contra la versión vigente de cada herramienta (AR-11); mientras la estructura no esté
 * verificada el archivo solo sale como borrador.
 */
return [
    'to_validate' => true,
    'documento_tabla3' => ['dni' => '01', 'ce' => '04', 'passport' => '07', 'ptp' => '23', 'cpp' => '26'],
    'documento_afpnet' => ['dni' => '0', 'ce' => '1', 'passport' => '4'],
    'sexo_tabla' => ['male' => '1', 'female' => '2'],
    'excepcion_afpnet' => ['unpaid_leave' => 'L', 'subsidy' => 'U', 'other' => 'O'],
    'tipo_trabajo_afpnet' => ['pe_construccion' => 'C', '*' => 'N'],
    'conciliacion_afpnet' => ['contribution' => 'afp_aporte', 'commission' => 'afp_comision', 'premium' => 'afp_prima'],
    'tolerancia_conciliacion' => '1.00',
];
