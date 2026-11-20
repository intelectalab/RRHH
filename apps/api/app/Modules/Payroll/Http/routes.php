<?php

use App\Modules\Payroll\Http\Controllers\PayrollConceptController;
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

    // CPT-1 a CPT-4 · Conceptos: catálogo con versiones, borrador, revisión, probador, publicación, retiro,
    // duplicado, afectaciones por base y contabilización por concepto.
    Route::get('/payroll-concepts', [PayrollConceptController::class, 'index'])->middleware('permission:payroll.concepts.view');
    Route::post('/payroll-concepts', [PayrollConceptController::class, 'store'])->middleware('permission:payroll.concepts.create');
    Route::get('/payroll-concepts/{id}', [PayrollConceptController::class, 'show'])->whereUuid('id')->middleware('permission:payroll.concepts.view');
    Route::patch('/payroll-concepts/{id}', [PayrollConceptController::class, 'update'])->whereUuid('id')->middleware('permission:payroll.concepts.update');
    Route::post('/payroll-concepts/{id}/validate', [PayrollConceptController::class, 'validateDraft'])->whereUuid('id')->middleware('permission:payroll.concepts.update');
    Route::post('/payroll-concepts/{id}/test', [PayrollConceptController::class, 'test'])->whereUuid('id')->middleware('permission:payroll.concepts.test');
    Route::post('/payroll-concepts/{id}/publish', [PayrollConceptController::class, 'publish'])->whereUuid('id')->middleware('permission:payroll.concepts.publish');
    Route::post('/payroll-concepts/{id}/retire', [PayrollConceptController::class, 'retire'])->whereUuid('id')->middleware('permission:payroll.concepts.retire');
    Route::post('/payroll-concepts/{id}/duplicate', [PayrollConceptController::class, 'duplicate'])->whereUuid('id')->middleware('permission:payroll.concepts.create');
    Route::put('/payroll-concepts/{id}/accounting', [PayrollConceptController::class, 'replaceAccounting'])->whereUuid('id')->middleware('permission:payroll.concepts.map');
    Route::get('/payroll-concept-bases', [PayrollConceptController::class, 'bases'])->middleware('permission:payroll.concepts.view');
    Route::get('/payroll-affectation-matrix', [PayrollConceptController::class, 'matrix'])->middleware('permission:payroll.concepts.view');
    Route::get('/payroll-accounting-check', [PayrollConceptController::class, 'accountingCheck'])->middleware('permission:payroll.concepts.view');
    Route::get('/accounting-accounts', [PayrollConceptController::class, 'accounts'])->middleware('permission:payroll.concepts.view');
    Route::post('/accounting-accounts', [PayrollConceptController::class, 'storeAccount'])->middleware('permission:payroll.accounting_accounts.update');
    Route::patch('/accounting-accounts/{id}', [PayrollConceptController::class, 'updateAccount'])->whereUuid('id')->middleware('permission:payroll.accounting_accounts.update');
});
