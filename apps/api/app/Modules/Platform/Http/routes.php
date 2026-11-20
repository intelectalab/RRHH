<?php

use App\Modules\Platform\Http\Controllers\ConsoleAuthController;
use App\Modules\Platform\Http\Controllers\ConsoleController;
use App\Modules\Platform\Http\Controllers\ConsoleOperationsController as Ops;
use App\Modules\Platform\Http\Controllers\FeatureFlagController;
use App\Modules\Platform\Http\Controllers\HelpArticleController;
use App\Modules\Platform\Http\Controllers\PlanController;
use App\Modules\Platform\Http\Controllers\SubscriptionController;
use App\Modules\Platform\Http\Controllers\SystemNoticesController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Modules\Platform\Http\Controllers\TenantModuleController;
use App\Modules\Platform\Http\Controllers\UsageController;
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

            // SAS-6 · Consumo frente a límites: actual, historia y comparación con otro plan.
            Route::get('/tenants/{id}/usage', [UsageController::class, 'show'])->middleware('platform.permission:platform.subscriptions.view');
            Route::get('/subscriptions/{id}/plan-fit', [UsageController::class, 'planFit'])->middleware('platform.permission:platform.subscriptions.view');

            // SAS-7 · Banderas de funcionalidad (cada cambio, auditado y sin desplegar).
            Route::get('/feature-flags', [FeatureFlagController::class, 'index'])->middleware('platform.permission:platform.feature_flags.view');
            Route::post('/feature-flags', [FeatureFlagController::class, 'store'])->middleware('platform.permission:platform.feature_flags.create');
            Route::get('/feature-flags/{id}', [FeatureFlagController::class, 'show'])->middleware('platform.permission:platform.feature_flags.view');
            Route::patch('/feature-flags/{id}', [FeatureFlagController::class, 'update'])->middleware('platform.permission:platform.feature_flags.update');
            Route::post('/feature-flags/{id}/archive', [FeatureFlagController::class, 'archive'])->middleware('platform.permission:platform.feature_flags.update');
            Route::post('/feature-flags/{id}/restore', [FeatureFlagController::class, 'restore'])->middleware('platform.permission:platform.feature_flags.update');
            Route::get('/feature-flags/{id}/tenants', [FeatureFlagController::class, 'tenants'])->middleware('platform.permission:platform.feature_flags.view');
            Route::post('/feature-flags/{id}/tenants', [FeatureFlagController::class, 'setTenant'])->middleware('platform.permission:platform.feature_flags.update');
            Route::post('/feature-flags/{id}/tenants/{tenantId}/remove', [FeatureFlagController::class, 'removeTenant'])->middleware('platform.permission:platform.feature_flags.update');
            Route::get('/tenants/{id}/feature-flags', [FeatureFlagController::class, 'forTenant'])->middleware('platform.permission:platform.feature_flags.view');

            // SAS-8 · Soporte auditado: sesiones con motivo, vencimiento y enlace de un solo uso (RN-SAS-08).
            Route::get('/support-sessions', [Ops::class, 'supportSessions'])->middleware('platform.permission:platform.support_sessions.view');
            Route::post('/support-sessions', [Ops::class, 'createSupportSession'])->middleware('platform.permission:platform.support_sessions.create');
            Route::get('/support-sessions/{id}', [Ops::class, 'supportSession'])->middleware('platform.permission:platform.support_sessions.view');
            Route::post('/support-sessions/{id}/end', [Ops::class, 'endSupportSession'])->middleware('platform.permission:platform.support_sessions.end');
            Route::get('/tenants/{id}/users', [Ops::class, 'tenantUsers'])->middleware('platform.permission:platform.support_sessions.create');

            // SAS-9 · Salud y monitoreo (sin datos de RRHH).
            Route::get('/health', [Ops::class, 'health'])->middleware('platform.permission:platform.health.view');
            Route::get('/health/failed-jobs', [Ops::class, 'failedJobs'])->middleware('platform.permission:platform.health.view');
            Route::post('/health/failed-jobs/{id}/retry', [Ops::class, 'retryFailedJob'])->middleware('platform.permission:platform.health.manage');
            Route::get('/health/outbox-events', [Ops::class, 'outboxEvents'])->middleware('platform.permission:platform.health.view');
            Route::post('/health/outbox-events/{id}/retry', [Ops::class, 'retryOutboxEvent'])->middleware('platform.permission:platform.health.manage');

            // SAS-12 · Copias de seguridad y restauración en aislado con doble aprobación (RN-SAS-09).
            Route::get('/backups', [Ops::class, 'backupList'])->middleware('platform.permission:platform.backups.view');
            Route::post('/backups', [Ops::class, 'backupCreate'])->middleware('platform.permission:platform.backups.create');
            Route::get('/backups/{id}', [Ops::class, 'backupShow'])->middleware('platform.permission:platform.backups.view');
            Route::post('/backups/{id}/restores', [Ops::class, 'backupRestore'])->middleware('platform.permission:platform.backups.restore');
            Route::get('/backup-restores', [Ops::class, 'restoreList'])->middleware('platform.permission:platform.backups.view');
            Route::post('/backup-restores/{id}/approve', [Ops::class, 'restoreApprove'])->middleware('platform.permission:platform.backups.restore');
            Route::post('/backup-restores/{id}/cancel', [Ops::class, 'restoreCancel'])->middleware('platform.permission:platform.backups.restore');

            // SAS-13 · Configuración global, avisos de mantenimiento y registros (auditoría, actividad y logs técnicos).
            Route::get('/settings', [Ops::class, 'settingsIndex'])->middleware('platform.permission:platform.settings.view');
            Route::patch('/settings/{key}', [Ops::class, 'settingsUpdate'])->middleware('platform.permission:platform.settings.update');
            Route::get('/notices', [Ops::class, 'noticeIndex'])->middleware('platform.permission:platform.settings.view');
            Route::post('/notices', [Ops::class, 'noticeStore'])->middleware('platform.permission:platform.settings.update');
            Route::patch('/notices/{id}', [Ops::class, 'noticeUpdate'])->middleware('platform.permission:platform.settings.update');
            Route::post('/notices/{id}/cancel', [Ops::class, 'noticeCancel'])->middleware('platform.permission:platform.settings.update');
            Route::get('/activity-events', [Ops::class, 'activity'])->middleware('platform.permission:platform.logs.view');
            Route::get('/technical-logs', [Ops::class, 'technicalLogs'])->middleware('platform.permission:platform.logs.view');

            // AYU-1 · Ayuda del producto: artículos por módulo, rol y pantalla con versiones (RN-AYU-02).
            Route::get('/help-articles', [HelpArticleController::class, 'index'])->middleware('platform.permission:platform.help_articles.view');
            Route::post('/help-articles', [HelpArticleController::class, 'store'])->middleware('platform.permission:platform.help_articles.create');
            Route::get('/help-articles/{id}', [HelpArticleController::class, 'show'])->middleware('platform.permission:platform.help_articles.view');
            Route::patch('/help-articles/{id}', [HelpArticleController::class, 'update'])->middleware('platform.permission:platform.help_articles.update');
            Route::post('/help-articles/{id}/publish', [HelpArticleController::class, 'publish'])->middleware('platform.permission:platform.help_articles.publish');
            Route::post('/help-articles/{id}/archive', [HelpArticleController::class, 'archive'])->middleware('platform.permission:platform.help_articles.archive');
            // AYU-5 · AYU-6 · Búsquedas sin resultado, valoración y consultas de soporte de las organizaciones.
            Route::get('/help-insights', [HelpArticleController::class, 'insights'])->middleware('platform.permission:platform.help_articles.view');
            Route::get('/help-escalations', [HelpArticleController::class, 'escalations'])->middleware('platform.permission:platform.help_articles.view');
        });
    });
});

// SAS-13 · Avisos de la plataforma vigentes, para la organización en curso (fuera de la consola).
Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/system-notices', SystemNoticesController::class);
});
