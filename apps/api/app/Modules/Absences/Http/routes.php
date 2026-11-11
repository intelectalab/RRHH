<?php

use App\Modules\Absences\Http\Controllers\AbsenceTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/absence-types', [AbsenceTypeController::class, 'index'])->middleware('permission:time.absence_types.view');
    Route::post('/absence-types', [AbsenceTypeController::class, 'store'])->middleware('permission:time.absence_types.create');
    Route::get('/absence-types/{id}', [AbsenceTypeController::class, 'show'])->middleware('permission:time.absence_types.view');
    Route::patch('/absence-types/{id}', [AbsenceTypeController::class, 'update'])->middleware('permission:time.absence_types.update');
    Route::get('/absence-types/{id}/rules', [AbsenceTypeController::class, 'rules'])->middleware('permission:time.absence_types.view');
    Route::post('/absence-types/{id}/archive', [AbsenceTypeController::class, 'archive'])->middleware('permission:time.absence_types.archive');
});
