<?php

use App\Modules\Platform\Http\Controllers\ConsoleAuthController;
use App\Modules\Platform\Http\Controllers\ConsoleController;
use App\Modules\Platform\Http\Controllers\PlanController;
use App\Modules\Platform\Http\Controllers\SubscriptionController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Modules\Platform\Http\Controllers\TenantModuleController;
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

            // SAS-2 · Organizaciones: alta, ficha, edición y estados por acción (ME-40).
            Route::get('/tenants', [TenantController::class, 'index'])->middleware('platform.permission:platform.tenants.view');
            Route::post('/tenants', [TenantController::class, 'store'])->middleware('platform.permission:platform.tenants.create');
            Route::get('/tenants/{id}', [TenantController::class, 'show'])->middleware('platform.permission:platform.tenants.view');
            Route::patch('/tenants/{id}', [TenantController::class, 'update'])->middleware('platform.permission:platform.tenants.update');
            Route::get('/tenants/{id}/status-events', [TenantController::class, 'statusEvents'])->middleware('platform.permission:platform.tenants.view');
            Route::post('/tenants/{id}/activate', [TenantController::class, 'activate'])->middleware('platform.permission:platform.tenants.reactivate');
            Route::post('/tenants/{id}/suspend', [TenantController::class, 'suspend'])->middleware('platform.permission:platform.tenants.suspend');
            Route::post('/tenants/{id}/block', [TenantController::class, 'block'])->middleware('platform.permission:platform.tenants.suspend');
            Route::post('/tenants/{id}/reactivate', [TenantController::class, 'reactivate'])->middleware('platform.permission:platform.tenants.reactivate');
            Route::post('/tenants/{id}/cancel', [TenantController::class, 'cancel'])->middleware('platform.permission:platform.tenants.cancel');

            // SAS-5 · Módulos comerciales por organización.
            Route::get('/tenants/{id}/modules', [TenantModuleController::class, 'index'])->middleware('platform.permission:platform.subscriptions.view');
            Route::post('/tenants/{id}/modules/{code}/activate', [TenantModuleController::class, 'activate'])->middleware('platform.permission:platform.subscriptions.update');
            Route::post('/tenants/{id}/modules/{code}/deactivate', [TenantModuleController::class, 'deactivate'])->middleware('platform.permission:platform.subscriptions.update');

            // SAS-3 · Planes comerciales (versionados) y complementos.
            Route::get('/plans', [PlanController::class, 'index'])->middleware('platform.permission:platform.plans.view');
            Route::post('/plans', [PlanController::class, 'store'])->middleware('platform.permission:platform.plans.create');
            Route::get('/plans/{id}', [PlanController::class, 'show'])->middleware('platform.permission:platform.plans.view');
            Route::patch('/plans/{id}', [PlanController::class, 'update'])->middleware('platform.permission:platform.plans.update');
            Route::get('/plans/{id}/versions', [PlanController::class, 'versions'])->middleware('platform.permission:platform.plans.view');
            Route::post('/plans/{id}/duplicate', [PlanController::class, 'duplicate'])->middleware('platform.permission:platform.plans.duplicate');
            Route::post('/plans/{id}/activate', [PlanController::class, 'activate'])->middleware('platform.permission:platform.plans.activate');
            Route::post('/plans/{id}/retire', [PlanController::class, 'retire'])->middleware('platform.permission:platform.plans.retire');
            Route::get('/addons', [PlanController::class, 'addons'])->middleware('platform.permission:platform.plans.view');
            Route::get('/addons/{id}', [PlanController::class, 'showAddon'])->middleware('platform.permission:platform.plans.view');
            Route::patch('/addons/{id}', [PlanController::class, 'updateAddon'])->middleware('platform.permission:platform.plans.update');
            Route::post('/addons/{id}/activate', [PlanController::class, 'activateAddon'])->middleware('platform.permission:platform.plans.activate');
            Route::post('/addons/{id}/retire', [PlanController::class, 'retireAddon'])->middleware('platform.permission:platform.plans.retire');

            // SAS-4 · Suscripciones: plan, complementos, prueba, cambio de plan y estados (ME-40).
            Route::get('/subscriptions', [SubscriptionController::class, 'index'])->middleware('platform.permission:platform.subscriptions.view');
            Route::post('/subscriptions', [SubscriptionController::class, 'store'])->middleware('platform.permission:platform.subscriptions.create');
            Route::get('/subscriptions/{id}', [SubscriptionController::class, 'show'])->middleware('platform.permission:platform.subscriptions.view');
            Route::patch('/subscriptions/{id}', [SubscriptionController::class, 'update'])->middleware('platform.permission:platform.subscriptions.update');
            Route::get('/subscriptions/{id}/events', [SubscriptionController::class, 'events'])->middleware('platform.permission:platform.subscriptions.view');
            Route::post('/subscriptions/{id}/change-plan', [SubscriptionController::class, 'changePlan'])->middleware('platform.permission:platform.subscriptions.change_plan');
            Route::post('/subscriptions/{id}/convert', [SubscriptionController::class, 'convert'])->middleware('platform.permission:platform.subscriptions.change_plan');
            Route::post('/subscriptions/{id}/suspend', [SubscriptionController::class, 'suspend'])->middleware('platform.permission:platform.subscriptions.suspend');
            Route::post('/subscriptions/{id}/reactivate', [SubscriptionController::class, 'reactivate'])->middleware('platform.permission:platform.subscriptions.reactivate');
            Route::post('/subscriptions/{id}/cancel', [SubscriptionController::class, 'cancel'])->middleware('platform.permission:platform.tenants.cancel');
            Route::post('/subscriptions/{id}/addons', [SubscriptionController::class, 'addAddon'])->middleware('platform.permission:platform.subscriptions.update');
            Route::post('/subscriptions/{id}/addons/{addonId}/remove', [SubscriptionController::class, 'removeAddon'])->middleware('platform.permission:platform.subscriptions.update');
        });
    });
});
