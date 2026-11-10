<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Audit\Http\Controllers\SensitiveAccessLogController;
use App\Modules\Audit\Http\Controllers\TrashController;
use Illuminate\Support\Facades\Route;

// Solo lectura: no existe ninguna ruta que cree, modifique ni borre auditoría (AUD-1).
// La papelera (CFG-9) restaura registros de los módulos; no toca la auditoría.
Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.logs.view');
    Route::get('/audit-logs/{id}', [AuditLogController::class, 'show'])->middleware('permission:audit.logs.view');
    Route::get('/trash', [TrashController::class, 'index'])->middleware('permission:audit.trash.view');
    Route::post('/trash/{id}/restore', [TrashController::class, 'restore'])->middleware('permission:audit.trash.restore');
    Route::get('/sensitive-access-logs', SensitiveAccessLogController::class)->middleware('permission:audit.sensitive_access.view');
});
