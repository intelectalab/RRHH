<?php

use App\Modules\Payroll\Http\Controllers\PayrollEngineController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\SmallBusinessController;
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

    // NOM-8 · Corridas en segundo plano con avance, reanudación y descarte; NOM-6 · resultado por relación,
    // líneas y explicación de cada monto; NOM-7 · recálculo retroactivo de una relación.
    Route::get('/payroll-runs', [PayrollRunController::class, 'index'])->middleware('permission:payroll.periods.view');
    Route::post('/payroll-runs', [PayrollRunController::class, 'store'])->middleware('permission:payroll.periods.calculate');
    Route::get('/payroll-runs/{id}', [PayrollRunController::class, 'show'])->middleware('permission:payroll.periods.view');
    Route::post('/payroll-runs/{id}/resume', [PayrollRunController::class, 'resume'])->middleware('permission:payroll.periods.calculate');
    Route::post('/payroll-runs/{id}/discard', [PayrollRunController::class, 'discard'])->middleware('permission:payroll.periods.calculate');
    Route::get('/payroll-runs/{id}/employments', [PayrollRunController::class, 'employments'])->middleware('permission:payroll.periods.view');
    Route::get('/payroll-run-employments/{id}/lines', [PayrollRunController::class, 'lines'])->middleware('permission:payroll.results.view');
    Route::get('/payroll-run-lines/{id}/explanation', [PayrollRunController::class, 'explanation'])->middleware('permission:payroll.results.trace.view');
    Route::post('/employments/{employment_id}/payroll-retroactive', [PayrollRunController::class, 'retroactive'])->middleware('permission:payroll.periods.calculate');

    // NOM-10 · Micro y pequeña empresa: ventas y dotación anuales, estado del régimen y cambio masivo.
    Route::get('/legal-entity-annual-figures', [SmallBusinessController::class, 'index'])->middleware('permission:payroll.entity_annual_figures.view');
    Route::post('/legal-entity-annual-figures', [SmallBusinessController::class, 'store'])->middleware('permission:payroll.entity_annual_figures.create');
    Route::get('/legal-entity-annual-figures/{id}', [SmallBusinessController::class, 'show'])->middleware('permission:payroll.entity_annual_figures.view');
    Route::patch('/legal-entity-annual-figures/{id}', [SmallBusinessController::class, 'update'])->middleware('permission:payroll.entity_annual_figures.update');
    Route::get('/legal-entities/{id}/small-business-status', [SmallBusinessController::class, 'status'])->middleware('permission:payroll.entity_annual_figures.view');
    Route::post('/legal-entities/{id}/labor-regime-change', [SmallBusinessController::class, 'changeRegime'])->middleware('permission:org.legal_entities.update');
});
