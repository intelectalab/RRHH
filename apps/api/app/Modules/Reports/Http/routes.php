<?php

use App\Modules\Reports\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

// SEG-12 · Exportaciones controladas: un rol sin reports.exports.create recibe 403.
Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/exports', [ExportController::class, 'index'])->middleware('permission:reports.exports.view');
    Route::post('/exports', [ExportController::class, 'store'])->middleware('permission:reports.exports.create');
    Route::get('/exports/{id}', [ExportController::class, 'show'])->middleware('permission:reports.exports.view');
    Route::get('/exports/{id}/download', [ExportController::class, 'download'])->middleware('permission:reports.exports.view');
});
