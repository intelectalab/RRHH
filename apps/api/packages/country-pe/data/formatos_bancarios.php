<?php

/**
 * Paquete de país Perú · Plantillas de archivo de pago (DEC-5, ADR 0020). Cada plantilla es datos: separador o ancho
 * fijo, cabecera, detalle, cierre y reglas de las cuentas (ver App\Modules\Payroll\Infrastructure\Payments\TemplateBankFile).
 *
 * El paquete trae dos plantillas genéricas (delimitada y de ancho fijo) que sirven de punto de partida. Los diseños
 * de registro propios de cada banco (haberes y CTS) no se publican aquí hasta contrastarlos con el banco: la
 * organización los carga como plantilla propia (bank_file_templates) y se marcan verificados cuando el banco acepta
 * un archivo de prueba. Por validar: todas.
 */
return [
    'pe_generico_delimitado' => [
        'bank_code' => null, 'name' => 'Genérico delimitado (punto y coma)', 'kind' => 'salary', 'verified' => false,
        'source' => 'Paquete Perú · plantilla de partida, por validar con cada banco',
        'definition' => [
            'separator' => ';', 'line_ending' => 'CRLF', 'encoding' => 'UTF-8', 'extension' => 'txt', 'mime' => 'text/plain',
            'header' => [['value' => 'H'], ['source' => 'header.legal_entity_tax_id'], ['source' => 'header.payment_date', 'date_format' => 'Ymd'],
                ['source' => 'header.currency'], ['source' => 'header.count'], ['source' => 'header.total', 'decimals' => 2]],
            'detail' => [['value' => 'D'], ['source' => 'item.sequence'], ['source' => 'item.employee_code'], ['source' => 'item.name', 'upper' => true],
                ['source' => 'item.account_type'], ['source' => 'item.account_number'], ['source' => 'item.interbank_code'],
                ['source' => 'item.currency'], ['source' => 'item.amount', 'decimals' => 2]],
            'trailer' => [],
            'account_rules' => ['account_number_pattern' => '/^\d{6,20}$/', 'require_interbank' => false],
        ],
    ],
    'pe_generico_ancho_fijo' => [
        'bank_code' => null, 'name' => 'Genérico de ancho fijo', 'kind' => 'salary', 'verified' => false,
        'source' => 'Paquete Perú · plantilla de partida, por validar con cada banco',
        'definition' => [
            'separator' => null, 'line_ending' => 'CRLF', 'encoding' => 'ISO-8859-1', 'extension' => 'txt', 'mime' => 'text/plain',
            'header' => [['value' => '1'], ['source' => 'header.legal_entity_tax_id', 'width' => 11], ['source' => 'header.payment_date', 'date_format' => 'Ymd', 'width' => 8],
                ['source' => 'header.count', 'width' => 6, 'pad' => 'left', 'pad_char' => '0'],
                ['source' => 'header.total', 'decimals' => 2, 'implied_decimals' => true, 'width' => 15, 'pad' => 'left', 'pad_char' => '0']],
            'detail' => [['value' => '2'], ['source' => 'item.account_number', 'width' => 20], ['source' => 'item.name', 'width' => 40, 'upper' => true],
                ['source' => 'item.amount', 'decimals' => 2, 'implied_decimals' => true, 'width' => 15, 'pad' => 'left', 'pad_char' => '0'],
                ['source' => 'item.employee_code', 'width' => 12]],
            'trailer' => [],
            'account_rules' => ['account_number_pattern' => '/^\d{6,20}$/', 'require_interbank' => false],
        ],
    ],
];
