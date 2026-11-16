<?php

use App\Modules\Imports\Http\Controllers\ImportController;
use App\Modules\Imports\Http\Controllers\SetupController;
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

    // IMP-3 · Primeros pasos · IMP-4 · Plantillas del país.
    Route::get('/setup-checklist', [SetupController::class, 'checklist'])->middleware('permission:imports.setup.use');
    Route::post('/setup-checklist/dismiss', [SetupController::class, 'dismiss'])->middleware('permission:imports.setup.use');
    Route::post('/setup-checklist/{code}/skip', [SetupController::class, 'skip'])->middleware('permission:imports.setup.use');
    Route::post('/setup-checklist/{code}/reopen', [SetupController::class, 'reopen'])->middleware('permission:imports.setup.use');
    Route::get('/setup/country-packs', [SetupController::class, 'packs'])->middleware('permission:imports.setup.use');
    Route::post('/setup/country-packs/{code}/apply', [SetupController::class, 'apply'])->middleware('permission:imports.setup.use');
});
