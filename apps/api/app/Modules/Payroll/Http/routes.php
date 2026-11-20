<?php

use App\Modules\Payroll\Http\Controllers\PayrollEngineController;
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
});
