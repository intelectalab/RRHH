<?php

use App\Modules\Notifications\Http\Controllers\DeliveryController;
use App\Modules\Notifications\Http\Controllers\EmailSenderController;
use App\Modules\Notifications\Http\Controllers\EmailWebhookController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\PreferenceController;
use App\Modules\Notifications\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

// NOT-3 · Avisos del proveedor de correo (rebotes): público, firmado por el proveedor.
Route::prefix('api/v1')->middleware('api')->group(function (): void {
    Route::post('/webhooks/email', EmailWebhookController::class)->middleware('throttle:600,1');
});

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);
    Route::get('/notification-preferences', [PreferenceController::class, 'show']);
    Route::put('/notification-preferences', [PreferenceController::class, 'update']);

    Route::get('/notification-templates', [TemplateController::class, 'index'])->middleware('permission:notifications.templates.view');
    Route::post('/notification-templates', [TemplateController::class, 'store'])->middleware('permission:notifications.templates.create');
    Route::get('/notification-templates/{id}', [TemplateController::class, 'show'])->middleware('permission:notifications.templates.view');
    Route::patch('/notification-templates/{id}', [TemplateController::class, 'update'])->middleware('permission:notifications.templates.update');
    Route::post('/notification-templates/{id}/request-approval', [TemplateController::class, 'requestApproval'])->middleware('permission:notifications.templates.update');
    Route::get('/notification-deliveries', [DeliveryController::class, 'index'])->middleware('permission:notifications.deliveries.view');
    Route::post('/notification-deliveries/{id}/clear-bounce', [EmailSenderController::class, 'clearBounce'])->middleware('permission:notifications.channels.update');
    Route::get('/message-channels/email', [EmailSenderController::class, 'show'])->middleware('permission:notifications.channels.update');
    Route::put('/message-channels/email', [EmailSenderController::class, 'update'])->middleware('permission:notifications.channels.update');
    Route::post('/message-channels/email/verify', [EmailSenderController::class, 'verify'])->middleware('permission:notifications.channels.update');
});
