<?php

// SEG-12 · List exports.
return [
    'columns' => [
        'display_name' => 'Name',
        'email' => 'Email',
        'status' => 'Status',
        'roles' => 'Roles',
        'last_login_at' => 'Last sign-in',
        'name' => 'Legal name',
        'trade_name' => 'Trade name',
        'tax_id' => 'Tax ID',
        'country' => 'Country',
        'currency' => 'Currency',
        'timezone' => 'Time zone',
    ],
    'watermark' => 'Exported by :name on :date',
    'resource_invalid' => 'This list cannot be exported.',
    'format_invalid' => 'Only CSV export is available for now.',
    'filter_invalid' => 'Filter not allowed for this list: :filter.',
    'not_ready' => 'The export is not ready yet.',
    'expired' => 'The export has expired; create a new one.',
];
