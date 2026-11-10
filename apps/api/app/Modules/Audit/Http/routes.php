<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Audit\Http\Controllers\SensitiveAccessLogController;
use Illuminate\Support\Facades\Route;

// Solo lectura: no existe ninguna ruta que cree, modifique ni borre auditoría (AUD-1).
Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.logs.view');
    Route::get('/audit-logs/{id}', [AuditLogController::class, 'show'])->middleware('permission:audit.logs.view');
    Route::get('/sensitive-access-logs', SensitiveAccessLogController::class)->middleware('permission:audit.sensitive_access.view');
});
