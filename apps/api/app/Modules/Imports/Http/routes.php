<?php

use App\Modules\Imports\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/import-types', [ImportController::class, 'types'])->middleware('permission:imports.imports.view');
    Route::get('/imports', [ImportController::class, 'index'])->middleware('permission:imports.imports.view');
    Route::post('/imports', [ImportController::class, 'store'])->middleware('permission:imports.imports.create');
    Route::get('/imports/{id}', [ImportController::class, 'show'])->middleware('permission:imports.imports.view');
    Route::get('/imports/{id}/rows', [ImportController::class, 'rows'])->middleware('permission:imports.imports.view');
    Route::get('/imports/{id}/errors/download', [ImportController::class, 'downloadErrors'])->middleware('permission:imports.imports.view');
    Route::post('/imports/{id}/map', [ImportController::class, 'map'])->middleware('permission:imports.imports.map');
    Route::post('/imports/{id}/validate', [ImportController::class, 'validate'])->middleware('permission:imports.imports.validate');
    Route::post('/imports/{id}/execute', [ImportController::class, 'execute'])->middleware('permission:imports.imports.execute');
    Route::post('/imports/{id}/cancel', [ImportController::class, 'cancel'])->middleware('permission:imports.imports.cancel');
    Route::post('/imports/{id}/revert', [ImportController::class, 'revert'])->middleware('permission:imports.imports.revert');
});
