<?php

use App\Modules\CoreHR\Http\Controllers\EmploymentController;
use App\Modules\CoreHR\Http\Controllers\PersonController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // COL-1 · Personas y deduplicación por documento.
    Route::get('/people', [PersonController::class, 'index'])->middleware('permission:people.people.view');
    Route::post('/people', [PersonController::class, 'store'])->middleware('permission:people.people.create');
    Route::get('/people/document-match', [PersonController::class, 'documentMatch'])->middleware('permission:people.people.view');
    Route::get('/people/{id}', [PersonController::class, 'show'])->middleware('permission:people.people.view');
    Route::patch('/people/{id}', [PersonController::class, 'update'])->middleware('permission:people.people.update');

    // COL-2 · COL-9 · Colaboradores: ingreso, listado, ficha básica y acciones de ME-01.
    Route::get('/employment-options', [EmploymentController::class, 'options'])->middleware('permission:people.employments.create');
    Route::get('/employments', [EmploymentController::class, 'index'])->middleware('permission:people.employments.view');
    Route::post('/employments', [EmploymentController::class, 'store'])->middleware('permission:people.employments.create');
    Route::get('/employments/{id}', [EmploymentController::class, 'show'])->middleware('permission:people.employments.view');
    Route::patch('/employments/{id}', [EmploymentController::class, 'update'])->middleware('permission:people.employments.update');
    Route::post('/employments/{id}/activate', [EmploymentController::class, 'activate'])->middleware('permission:people.employments.activate');
    Route::post('/employments/{id}/cancel', [EmploymentController::class, 'cancel'])->middleware('permission:people.employments.update');
});
