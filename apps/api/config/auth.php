<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;

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
        // SAS-1: consola del superadministrador; cookie, tabla y sesiones propias, sin organización.
        'platform' => [
            'driver' => 'platform-session',
            'provider' => 'platform_users',
        ],
    ],

    'providers' => [
        // Solo describe el modelo de usuario; el guard ayni-session resuelve la sesión por cookie.
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
        'platform_users' => [
            'driver' => 'eloquent',
            'model' => PlatformUser::class,
        ],
    ],
];
