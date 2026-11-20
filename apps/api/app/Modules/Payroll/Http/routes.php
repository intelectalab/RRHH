<?php

use App\Modules\Payroll\Http\Controllers\LegalParametersController;
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

    // PAR-1 · Parámetros legales con vigencia (valores del país: solo lectura; la organización registra los propios).
    Route::get('/legal-parameters', [LegalParametersController::class, 'index'])->middleware('permission:payroll.legal_parameters.view');
    Route::get('/legal-parameters/{id}', [LegalParametersController::class, 'show'])->middleware('permission:payroll.legal_parameters.view');
    Route::post('/legal-parameters/{id}/add-value', [LegalParametersController::class, 'addValue'])->middleware('permission:payroll.legal_parameters.add_value');
    Route::post('/legal-parameters/{id}/confirm', [LegalParametersController::class, 'confirm'])->middleware('permission:payroll.legal_parameters.confirm');
    // PAR-2 · PAR-3 · PAR-5 · Tablas por AFP, escala de quinta y parámetros de cada régimen.
    Route::get('/pension-fund-rates', [LegalParametersController::class, 'pensionFundRates'])->middleware('permission:payroll.legal_parameters.view');
    Route::get('/fifth-category-scale', [LegalParametersController::class, 'fifthCategory'])->middleware('permission:payroll.legal_parameters.view');
    Route::get('/payroll-regimes/{id}/parameters', [LegalParametersController::class, 'regimeParameters'])->middleware('permission:payroll.regimes.view');
    // PAR-6 · Tablas salariales por convenio (lotes de parámetros) y su verificación contra el acta.
    Route::get('/wage-tables', [LegalParametersController::class, 'wageTables'])->middleware('permission:payroll.wage_tables.view');
    Route::get('/wage-tables/{id}', [LegalParametersController::class, 'wageTable'])->middleware('permission:payroll.wage_tables.view');
    Route::post('/wage-tables/{id}/verify', [LegalParametersController::class, 'verifyWageTable'])->middleware('permission:payroll.wage_tables.verify');
    // PAR-7 · Novedades normativas con los periodos que tocan.
    Route::get('/legal-updates', [LegalParametersController::class, 'legalUpdates'])->middleware('permission:payroll.legal_parameters.view');
});
