<?php

use App\Modules\Contracts\Http\Controllers\ContractTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/contract-types', [ContractTypeController::class, 'index'])->middleware('permission:contracts.contract_types.view');
    Route::post('/contract-types', [ContractTypeController::class, 'store'])->middleware('permission:contracts.contract_types.create');
    Route::get('/contract-types/{id}', [ContractTypeController::class, 'show'])->middleware('permission:contracts.contract_types.view');
    Route::patch('/contract-types/{id}', [ContractTypeController::class, 'update'])->middleware('permission:contracts.contract_types.update');
});
