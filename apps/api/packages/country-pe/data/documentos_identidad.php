<?php

/**
 * Paquete de país Perú · Formato de los documentos de identidad (COL-1).
 *
 * Mismos códigos que person_documents.doc_type. pattern valida el número ya normalizado (sin espacios,
 * guiones ni puntos, en mayúsculas). Fuente: Tabla 3 de SUNAT (tipos de documento) y el registro
 * laboral, que admite hasta 12 caracteres para los documentos de extranjeros.
 *
 * Por validar: la longitud exacta del carné de extranjería, del PTP y del CPP (aquí se acepta el rango
 * que admite el registro laboral, no un largo fijo).
 */
return [
    'dni' => ['pattern' => '/^\d{8}$/', 'example' => '12345678', 'to_validate' => false],
    'ce' => ['pattern' => '/^[A-Z0-9]{8,12}$/', 'example' => '001234567', 'to_validate' => true],
    'passport' => ['pattern' => '/^[A-Z0-9]{6,12}$/', 'example' => 'AB1234567', 'to_validate' => false],
    'ptp' => ['pattern' => '/^[A-Z0-9]{8,12}$/', 'example' => '000123456', 'to_validate' => true],
    'cpp' => ['pattern' => '/^[A-Z0-9]{8,12}$/', 'example' => '000123456', 'to_validate' => true],
    'other' => ['pattern' => '/^[A-Z0-9]{4,20}$/', 'example' => 'X1234', 'to_validate' => false],
];
