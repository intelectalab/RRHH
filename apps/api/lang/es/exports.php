<?php

// SEG-12 · Exportaciones de listados.
return [
    'columns' => [
        'display_name' => 'Nombre',
        'email' => 'Correo',
        'status' => 'Estado',
        'roles' => 'Roles',
        'last_login_at' => 'Último ingreso',
        'name' => 'Razón social',
        'trade_name' => 'Nombre comercial',
        'tax_id' => 'RUC',
        'country' => 'País',
        'currency' => 'Moneda',
        'timezone' => 'Zona horaria',
    ],
    'watermark' => 'Exportado por :name el :date',
    'resource_invalid' => 'Este listado no se puede exportar.',
    'format_invalid' => 'Por ahora solo se exporta en CSV.',
    'filter_invalid' => 'Filtro no admitido para este listado: :filter.',
    'not_ready' => 'La exportación todavía no está lista.',
    'expired' => 'La exportación venció; genera una nueva.',
];
