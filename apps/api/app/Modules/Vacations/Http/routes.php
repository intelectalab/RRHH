<?php

use App\Modules\Vacations\Http\Controllers\VacationBalanceController;
use App\Modules\Vacations\Http\Controllers\VacationPolicyController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // VAC-1 · Políticas de vacaciones con reglas con vigencia.
    Route::get('/vacation-policies', [VacationPolicyController::class, 'index'])->middleware('permission:time.vacation_policies.view');
    Route::post('/vacation-policies', [VacationPolicyController::class, 'store'])->middleware('permission:time.vacation_policies.update');
    Route::get('/vacation-policies/{id}', [VacationPolicyController::class, 'show'])->middleware('permission:time.vacation_policies.view');
    Route::patch('/vacation-policies/{id}', [VacationPolicyController::class, 'update'])->middleware('permission:time.vacation_policies.update');
    Route::get('/vacation-policies/{id}/rules', [VacationPolicyController::class, 'rules'])->middleware('permission:time.vacation_policies.view');
    Route::post('/vacation-policies/{id}/archive', [VacationPolicyController::class, 'archive'])->middleware('permission:time.vacation_policies.update');
    Route::post('/vacation-policies/{id}/restore', [VacationPolicyController::class, 'restore'])->middleware('permission:time.vacation_policies.update');
    Route::get('/vacation-legal-limits', [VacationPolicyController::class, 'legal'])->middleware('permission:time.vacation_policies.view');

    // VAC-2 · Saldos (suma del libro) y ajustes con motivo y sustento.
    Route::get('/vacation-balances', [VacationBalanceController::class, 'index'])->middleware('permission:time.vacation_balances.view');
    Route::get('/vacation-balances/{id}', [VacationBalanceController::class, 'show'])->middleware('permission:time.vacation_balances.view');
    Route::get('/vacation-balances/{id}/ledger', [VacationBalanceController::class, 'entries'])->middleware('permission:time.vacation_balances.view');
    Route::post('/vacation-balances/{id}/adjust', [VacationBalanceController::class, 'adjust'])->middleware('permission:time.vacation_balances.adjust');
});
