<?php

use App\Modules\Payroll\Http\Controllers\PayrollEngineController;
use App\Modules\Payroll\Http\Controllers\PayrollIncidentController;
use App\Modules\Payroll\Http\Controllers\PayrollPeriodController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // NOM-2 · Regímenes laborales del país (estrategia del paquete) y régimen de cada relación con vigencia.
    Route::get('/payroll-regimes', [PayrollEngineController::class, 'regimes'])->middleware('permission:payroll.regimes.view');
    Route::get('/payroll-regimes/{id}', [PayrollEngineController::class, 'regime'])->middleware('permission:payroll.regimes.view');
    Route::get('/employments/{employment_id}/payroll-regime', [PayrollEngineController::class, 'employmentRegime'])->middleware('permission:payroll.regimes.view');
    Route::post('/employments/{employment_id}/payroll-regime', [PayrollEngineController::class, 'changeEmploymentRegime'])->middleware('permission:people.employments.update');

    // NOM-3 · Revisión de una fórmula antes de guardarla; NOM-4 · orden de cálculo; NOM-5 · banco de pruebas.
    Route::post('/payroll-formulas/validate', [PayrollEngineController::class, 'validateFormula'])->middleware('permission:payroll.concepts.test');
    Route::get('/payroll-calculation-order', [PayrollEngineController::class, 'order'])->middleware('permission:payroll.concepts.view');
    Route::post('/payroll-test-bench', [PayrollEngineController::class, 'bench'])->middleware('permission:payroll.concepts.test');

    // PER-1 · Grupos y periodos de planilla (ME-19); calcular, simular y registrar el pago llegan con PER-4, PER-7 y PER-10.
    Route::get('/payroll-groups', [PayrollPeriodController::class, 'groups'])->middleware('permission:payroll.periods.view');
    Route::post('/payroll-groups', [PayrollPeriodController::class, 'storeGroup'])->middleware('permission:payroll.payroll_groups.update');
    Route::get('/payroll-groups/{id}', [PayrollPeriodController::class, 'group'])->middleware('permission:payroll.periods.view');
    Route::patch('/payroll-groups/{id}', [PayrollPeriodController::class, 'updateGroup'])->middleware('permission:payroll.payroll_groups.update');
    Route::post('/payroll-groups/{id}/members', [PayrollPeriodController::class, 'assignMember'])->middleware('permission:payroll.payroll_groups.update');
    Route::get('/payroll-periods', [PayrollPeriodController::class, 'index'])->middleware('permission:payroll.periods.view');
    Route::post('/payroll-periods', [PayrollPeriodController::class, 'store'])->middleware('permission:payroll.periods.create');
    Route::get('/payroll-periods/{id}', [PayrollPeriodController::class, 'show'])->middleware('permission:payroll.periods.view');
    Route::get('/payroll-periods/{id}/population', [PayrollPeriodController::class, 'population'])->middleware('permission:payroll.periods.view');
    Route::post('/payroll-periods/{id}/close-incidents', [PayrollPeriodController::class, 'closeIncidents'])->middleware('permission:payroll.periods.calculate');
    Route::post('/payroll-periods/{id}/reopen-incidents', [PayrollPeriodController::class, 'reopenIncidents'])->middleware('permission:payroll.periods.calculate');
    Route::post('/payroll-periods/{id}/submit', [PayrollPeriodController::class, 'submit'])->middleware('permission:payroll.periods.submit');
    Route::post('/payroll-periods/{id}/approve', [PayrollPeriodController::class, 'approve'])->middleware('permission:payroll.periods.approve');
    Route::post('/payroll-periods/{id}/reject', [PayrollPeriodController::class, 'reject'])->middleware('permission:payroll.periods.reject');
    Route::post('/payroll-periods/{id}/close', [PayrollPeriodController::class, 'close'])->middleware('permission:payroll.periods.close');
    Route::post('/payroll-periods/{id}/reopen', [PayrollPeriodController::class, 'reopen'])->middleware('permission:payroll.periods.reopen');
    Route::post('/payroll-periods/{id}/reopen-calculation', [PayrollPeriodController::class, 'reopenCalculation'])->middleware('permission:payroll.periods.calculate');

    // PER-2 · Incidencias del periodo.
    Route::get('/payroll-incidents', [PayrollIncidentController::class, 'index'])->middleware('permission:payroll.incidents.view');
    Route::post('/payroll-incidents', [PayrollIncidentController::class, 'store'])->middleware('permission:payroll.incidents.create');
    Route::get('/payroll-incidents/{id}', [PayrollIncidentController::class, 'show'])->middleware('permission:payroll.incidents.view');
    Route::patch('/payroll-incidents/{id}', [PayrollIncidentController::class, 'update'])->middleware('permission:payroll.incidents.update');
    Route::delete('/payroll-incidents/{id}', [PayrollIncidentController::class, 'destroy'])->middleware('permission:payroll.incidents.delete');
    Route::post('/payroll-incidents/{id}/approve', [PayrollIncidentController::class, 'approve'])->middleware('permission:payroll.incidents.approve');
    Route::post('/payroll-incidents/{id}/reject', [PayrollIncidentController::class, 'reject'])->middleware('permission:payroll.incidents.approve');
    Route::post('/payroll-periods/{id}/incidents/bulk', [PayrollIncidentController::class, 'bulk'])->middleware('permission:payroll.incidents.create');

    // PER-3 · Consumo de ausencias y vacaciones con corte registrado.
    Route::post('/payroll-periods/{id}/consume-time', [PayrollPeriodController::class, 'consumeTime'])->middleware('permission:payroll.incidents.create');
    Route::get('/payroll-periods/{id}/time-summary', [PayrollPeriodController::class, 'timeSummary'])->middleware('permission:payroll.periods.view');
});
