<?php

/*
 * Formato del identificador fiscal por país (validación de forma, no un parámetro legal).
 * Lo amplían los paquetes de país.
 */
return [
    'PE' => [
        'tax_id_label' => 'RUC',
        // RUC: 11 dígitos; prefijos de persona natural (10, 15, 17) o jurídica (20).
        'tax_id_pattern' => '/^(10|15|16|17|20)\d{9}$/',
    ],
];
