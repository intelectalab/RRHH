<?php

use App\Modules\Audit\Http\Controllers\ActivityEventController;
use App\Modules\Audit\Http\Controllers\AuditArchiveController;
use App\Modules\Audit\Http\Controllers\AuditIntegrityController;
use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Audit\Http\Controllers\SensitiveAccessLogController;
use App\Modules\Audit\Http\Controllers\TrashController;
use Illuminate\Support\Facades\Route;

// Solo lectura: no existe ninguna ruta que cree, modifique ni borre auditoría (AUD-1).
// La papelera (CFG-9) restaura registros de los módulos; no toca la auditoría.
// La verificación de integridad y el archivado en frío corren como tareas programadas (AUD-5).
Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.logs.view');
    Route::get('/audit-logs/{id}', [AuditLogController::class, 'show'])->middleware('permission:audit.logs.view');
    Route::get('/audit-integrity-checks', [AuditIntegrityController::class, 'checks'])->middleware('permission:audit.logs.view');
    Route::get('/audit-retention', [AuditIntegrityController::class, 'retention'])->middleware('permission:audit.logs.view');
    Route::get('/audit-archives', [AuditArchiveController::class, 'index'])->middleware('permission:audit.logs.view');
    Route::get('/audit-archives/{id}', [AuditArchiveController::class, 'show'])->middleware('permission:audit.logs.view');
    Route::get('/audit-archives/{id}/entries', [AuditArchiveController::class, 'entries'])->middleware('permission:audit.logs.view');
    Route::get('/activity-events', ActivityEventController::class)->middleware('permission:audit.activity.view');
    Route::get('/trash', [TrashController::class, 'index'])->middleware('permission:audit.trash.view');
    Route::post('/trash/{id}/restore', [TrashController::class, 'restore'])->middleware('permission:audit.trash.restore');
    Route::get('/sensitive-access-logs', SensitiveAccessLogController::class)->middleware('permission:audit.sensitive_access.view');
});
