<?php

/**
 * Paquete de país Perú · Formato de las cuentas de pago (COL-8) y del código de afiliado a una AFP (COL-7).
 *
 * pattern valida el valor ya normalizado (solo dígitos o letras, sin espacios ni guiones).
 *
 * - interbank_code: el código de cuenta interbancario (CCI) tiene entidad (3), oficina (3), cuenta (12)
 *   y dos dígitos de control. check_digits nombra el algoritmo que los verifica; enforce_check_digits
 *   decide si un CCI con dígitos de control que no cuadran se rechaza (true) o se acepta con un aviso
 *   (false) mientras el algoritmo no se contraste con la fuente bancaria.
 * - account_number: el número de cuenta varía por banco; se admite un rango de dígitos.
 * - affiliate_code: código único del sistema privado de pensiones (CUSPP).
 *
 * Por validar: el algoritmo de los dígitos de control del CCI, el rango de dígitos del número de cuenta
 * por banco y el largo del CUSPP.
 */
return [
    'interbank_code' => ['pattern' => '/^\d{20}$/', 'example' => '00219300123456789013', 'check_digits' => 'pe_cci', 'enforce_check_digits' => false, 'to_validate' => true],
    'account_number' => ['pattern' => '/^\d{6,20}$/', 'example' => '1930012345678', 'to_validate' => true],
    'affiliate_code' => ['pattern' => '/^[A-Z0-9]{12}$/', 'example' => '123456ABCDE7', 'to_validate' => true],
];
