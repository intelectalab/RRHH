<?php

return [
    'defaults' => [
        'guard' => 'ayni',
        'passwords' => null,
    ],

    'guards' => [
        // Sesión web por cookie HttpOnly (ADR 0004); resuelta dentro de la organización activa.
        'ayni' => [
            'driver' => 'ayni-session',
        ],
    ],

    'providers' => [],
];
