<?php

return [
    'entities' => [
        'legal_entities' => 'Empresas',
        'employees' => 'Colaboradores',
        'contracts' => 'Contratos',
        'vacation_balances' => 'Saldos de vacaciones',
        'salaries' => 'Sueldos',
        'attendance' => 'Asistencia',
        'concepts' => 'Conceptos',
        'history' => 'Históricos',
        'org_structure' => 'Estructura',
        'dependents' => 'Dependientes',
        'positions' => 'Posiciones',
    ],
    'columns' => [
        'legal_entities' => [
            'name' => 'Razón social',
            'tax_id' => 'Número fiscal (RUC)',
            'trade_name' => 'Nombre comercial',
            'payroll_regime' => 'Régimen laboral',
            'country' => 'País',
            'currency' => 'Moneda',
            'timezone' => 'Zona horaria',
        ],
    ],
    'file' => [
        'empty' => 'El archivo está vacío o no se pudo leer.',
        'no_headers' => 'La primera fila del archivo debe tener los nombres de las columnas.',
        'duplicate_headers' => 'Hay columnas con el mismo nombre en la primera fila.',
        'no_rows' => 'El archivo no tiene filas de datos debajo de los nombres de columna.',
        'too_many_rows' => 'El archivo supera las :max filas permitidas; divídelo en partes.',
        'encoding' => 'No se reconoce la codificación del archivo; guárdalo como CSV UTF-8.',
        'unreadable' => 'No se pudo leer el archivo. Usa CSV o Excel (.xlsx).',
    ],
    'mapping' => [
        'invalid' => 'El mapeo debe indicar, para cada columna, la cabecera del archivo.',
        'unknown_column' => 'La columna «:column» no existe para este tipo de datos.',
        'unknown_header' => 'La cabecera «:header» no está en el archivo.',
        'header_twice' => 'La cabecera «:header» está asignada a dos columnas.',
        'missing_required' => 'Falta asignar la columna obligatoria «:column».',
    ],
    'errors' => [
        'required' => '«:column» es obligatorio.',
        'duplicate_in_file' => 'Repite los datos de la fila :row del mismo archivo.',
    ],
    'warnings' => [
        'duplicate_file' => 'Este archivo ya se subió antes para el mismo tipo de datos. Revisa que no lo estés importando dos veces.',
        'sensitive_ignored' => 'La columna «:column» se ignora: no tienes permiso para importar ese dato.',
    ],
    'csv' => [
        'row' => 'Fila',
        'reason' => 'Motivo',
    ],
    'entity_invalid' => 'Ese tipo de datos no se puede importar.',
    'confirm_required' => 'Revisa la vista previa y confirma para importar.',
    'nothing_to_import' => 'No hay filas válidas para importar.',
    'revert_blocked' => 'No se puede deshacer: :n registros del lote ya se usan en otros procesos.',
    'no_errors' => 'Esta importación no tiene filas con error.',
    'failed_unexpected' => 'Ocurrió un error inesperado al importar; no se guardó ningún registro.',
];
