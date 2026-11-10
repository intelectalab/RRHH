<?php

use App\Modules\Identity\Infrastructure\Models\User;

return [
    'defaults' => [
        'guard' => 'ayni',
        'passwords' => null,
    ],

    'guards' => [
        // Sesión web por cookie HttpOnly (ADR 0004); resuelta dentro de la organización activa.
        'ayni' => [
            'driver' => 'ayni-session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        // Solo describe el modelo de usuario; el guard ayni-session resuelve la sesión por cookie.
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],
];
