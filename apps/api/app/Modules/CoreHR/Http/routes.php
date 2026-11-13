<?php

use App\Modules\CoreHR\Http\Controllers\BankAccountController;
use App\Modules\CoreHR\Http\Controllers\DependentController;
use App\Modules\CoreHR\Http\Controllers\EmploymentController;
use App\Modules\CoreHR\Http\Controllers\InsuranceCatalogController;
use App\Modules\CoreHR\Http\Controllers\PersonController;
use App\Modules\CoreHR\Http\Controllers\SocialSecurityController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // COL-1 · Personas y deduplicación por documento.
    Route::get('/people', [PersonController::class, 'index'])->middleware('permission:people.people.view');
    Route::post('/people', [PersonController::class, 'store'])->middleware('permission:people.people.create');
    Route::get('/people/document-match', [PersonController::class, 'documentMatch'])->middleware('permission:people.people.view');
    Route::get('/people/{id}', [PersonController::class, 'show'])->middleware('permission:people.people.view');
    Route::patch('/people/{id}', [PersonController::class, 'update'])->middleware('permission:people.people.update');

    // COL-2 · COL-9 · Colaboradores: ingreso, listado, ficha básica y acciones de ME-01.
    Route::get('/employment-options', [EmploymentController::class, 'options'])->middleware('permission:people.employments.create');
    Route::get('/employments', [EmploymentController::class, 'index'])->middleware('permission:people.employments.view');
    Route::post('/employments', [EmploymentController::class, 'store'])->middleware('permission:people.employments.create');
    Route::get('/employments/{id}', [EmploymentController::class, 'show'])->middleware('permission:people.employments.view');
    Route::patch('/employments/{id}', [EmploymentController::class, 'update'])->middleware('permission:people.employments.update');
    Route::post('/employments/{id}/activate', [EmploymentController::class, 'activate'])->middleware('permission:people.employments.activate');
    Route::post('/employments/{id}/cancel', [EmploymentController::class, 'cancel'])->middleware('permission:people.employments.update');

    // COL-6 · Derechohabientes de la persona, vistos desde una de sus relaciones laborales.
    $dep = '/employments/{employment_id}/dependents';
    Route::get($dep, [DependentController::class, 'index'])->middleware('permission:people.dependents.view');
    Route::post($dep, [DependentController::class, 'store'])->middleware('permission:people.dependents.create');
    Route::get($dep.'/{id}', [DependentController::class, 'show'])->middleware('permission:people.dependents.view');
    Route::patch($dep.'/{id}', [DependentController::class, 'update'])->middleware('permission:people.dependents.update');
    Route::delete($dep.'/{id}', [DependentController::class, 'destroy'])->middleware('permission:people.dependents.delete');
    Route::post($dep.'/{id}/deregister', [DependentController::class, 'deregister'])->middleware('permission:people.dependents.update');
    Route::get($dep.'/{id}/proof', [DependentController::class, 'proof'])->middleware('permission:people.dependents.view');
    Route::post($dep.'/{id}/proof', [DependentController::class, 'uploadProof'])->middleware('permission:people.dependents.update');

    // COL-7 · Previsión, salud y seguros del trabajador, con vigencia.
    $emp = '/employments/{employment_id}';
    Route::get($emp.'/pension-affiliations', [SocialSecurityController::class, 'pensionIndex'])->middleware('permission:people.employments.view');
    Route::post($emp.'/pension-affiliations', [SocialSecurityController::class, 'pensionStore'])->middleware('permission:people.employments.update');
    Route::get($emp.'/health-regimes', [SocialSecurityController::class, 'healthIndex'])->middleware('permission:people.employments.view');
    Route::post($emp.'/health-regimes', [SocialSecurityController::class, 'healthStore'])->middleware('permission:people.employments.update');
    Route::get($emp.'/insurance-coverages', [SocialSecurityController::class, 'coverageIndex'])->middleware('permission:people.employments.view');
    Route::post($emp.'/insurance-coverages', [SocialSecurityController::class, 'coverageStore'])->middleware('permission:people.employments.update');
    Route::post($emp.'/insurance-coverages/{id}/end', [SocialSecurityController::class, 'coverageEnd'])->middleware('permission:people.employments.update');
    Route::get($emp.'/life-insurance-declarations', [SocialSecurityController::class, 'declarationIndex'])->middleware('permission:people.employments.view');
    Route::post($emp.'/life-insurance-declarations', [SocialSecurityController::class, 'declarationStore'])->middleware('permission:people.employments.update');
    Route::get($emp.'/life-insurance-declarations/{id}/file', [SocialSecurityController::class, 'declarationFile'])->middleware('permission:people.employments.view');
    Route::get('/pension-funds', [InsuranceCatalogController::class, 'pensionFunds'])->middleware('permission:people.employments.view');
    Route::get('/health-providers', [InsuranceCatalogController::class, 'healthProviders'])->middleware('permission:people.employments.view');
    Route::post('/health-providers', [InsuranceCatalogController::class, 'storeHealthProvider'])->middleware('permission:org.legal_entities.update');
    Route::get('/insurance-policies', [InsuranceCatalogController::class, 'policies'])->middleware('permission:org.legal_entities.view');
    Route::post('/insurance-policies', [InsuranceCatalogController::class, 'storePolicy'])->middleware('permission:org.legal_entities.update');
    Route::patch('/insurance-policies/{id}', [InsuranceCatalogController::class, 'updatePolicy'])->middleware('permission:org.legal_entities.update');

    // COL-8 · Cuentas de pago con aprobación de cuatro ojos (RN-COL-11).
    Route::get($emp.'/bank-accounts', [BankAccountController::class, 'index'])->middleware('permission:people.employments.view');
    Route::post($emp.'/bank-accounts', [BankAccountController::class, 'store'])->middleware('permission:people.people.bank_account.edit');
    Route::post($emp.'/bank-accounts/{id}/approve', [BankAccountController::class, 'approve'])->middleware('permission:people.change_requests.approve');
    Route::post($emp.'/bank-accounts/{id}/reject', [BankAccountController::class, 'reject'])->middleware('permission:people.change_requests.approve');
    Route::post($emp.'/bank-accounts/{id}/cancel', [BankAccountController::class, 'cancel'])->middleware('permission:people.people.bank_account.edit');
});
