<?php

use App\Modules\Vacations\Http\Controllers\VacationBalanceController;
use App\Modules\Vacations\Http\Controllers\VacationPolicyController;
use App\Modules\Vacations\Http\Controllers\VacationRequestController;
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

    // VAC-3 · Solicitudes de vacaciones (aprobar y rechazar: centro de aprobaciones).
    Route::get('/vacation-requests', [VacationRequestController::class, 'index'])->middleware('permission:time.vacation_requests.view');
    Route::post('/vacation-requests', [VacationRequestController::class, 'store'])->middleware('permission:time.vacation_requests.create');
    Route::post('/vacation-requests/simulate', [VacationRequestController::class, 'simulate'])->middleware('permission:time.vacation_requests.create');
    Route::get('/vacation-requests/{id}', [VacationRequestController::class, 'show'])->middleware('permission:time.vacation_requests.view');
    Route::patch('/vacation-requests/{id}', [VacationRequestController::class, 'update'])->middleware('permission:time.vacation_requests.update');
    Route::post('/vacation-requests/{id}/submit', [VacationRequestController::class, 'submit'])->middleware('permission:time.vacation_requests.submit');
    Route::post('/vacation-requests/{id}/register', [VacationRequestController::class, 'register'])->middleware('permission:time.vacation_requests.validate');
    Route::post('/vacation-requests/{id}/cancel', [VacationRequestController::class, 'cancel'])->middleware('permission:time.vacation_requests.cancel');
    Route::get('/vacation-requests/{id}/overlaps', [VacationRequestController::class, 'overlaps'])->middleware('permission:time.vacation_requests.view');

    // VAC-4 · Calendario de ausencias del equipo (vacaciones y ausencias, aprobadas y en trámite).
    Route::get('/team-calendar', [VacationRequestController::class, 'teamCalendar'])->middleware('permission:time.vacation_requests.view');
});
