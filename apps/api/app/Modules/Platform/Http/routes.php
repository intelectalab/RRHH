<?php

use App\Modules\Platform\Http\Controllers\ConsoleAuthController;
use App\Modules\Platform\Http\Controllers\ConsoleController;
use App\Modules\Platform\Http\Middleware\ConsoleBoundary;
use App\Modules\Platform\Http\Middleware\EnsureConsoleSecondFactor;
use Illuminate\Support\Facades\Route;

/*
 * SAS-1 · Consola del superadministrador (x-permission de openapi.yaml):
 *  - public            → sin sesión
 *  - platform          → sesión de la consola (auth:platform), aun con el segundo paso pendiente
 *  - platform.<código> → auth:platform + segundo factor + platform.permission:<código>
 * Toda la consola pasa por ConsoleBoundary: 404 desde el host de una organización.
 */
Route::prefix('api/v1/platform')->middleware(['api', ConsoleBoundary::ALIAS])->group(function (): void {
    Route::post('/auth/login', [ConsoleAuthController::class, 'login'])->middleware('throttle:platform-login');

    Route::middleware('auth:platform')->group(function (): void {
        Route::get('/me', [ConsoleAuthController::class, 'me']);
        Route::post('/auth/logout', [ConsoleAuthController::class, 'logout']);
        Route::post('/auth/mfa/totp', [ConsoleAuthController::class, 'startTotp'])->middleware('throttle:platform-login');
        Route::post('/auth/mfa/totp/confirm', [ConsoleAuthController::class, 'confirmTotp'])->middleware('throttle:platform-login');
        Route::post('/auth/mfa/verify', [ConsoleAuthController::class, 'verify'])->middleware('throttle:platform-login');

        Route::middleware(EnsureConsoleSecondFactor::ALIAS)->group(function (): void {
            Route::get('/metrics', [ConsoleController::class, 'metrics'])->middleware('platform.permission:platform.metrics.view');
            Route::get('/audit-events', [ConsoleController::class, 'auditEvents'])->middleware('platform.permission:platform.audit.view');
        });
    });
});
