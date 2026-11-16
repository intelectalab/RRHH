<?php

use App\Modules\CoreHR\Http\Controllers\BankAccountController;
use App\Modules\CoreHR\Http\Controllers\CompensationController;
use App\Modules\CoreHR\Http\Controllers\DependentController;
use App\Modules\CoreHR\Http\Controllers\EmploymentController;
use App\Modules\CoreHR\Http\Controllers\EmploymentProfileController;
use App\Modules\CoreHR\Http\Controllers\HomeController;
use App\Modules\CoreHR\Http\Controllers\InsuranceCatalogController;
use App\Modules\CoreHR\Http\Controllers\MeEmploymentController;
use App\Modules\CoreHR\Http\Controllers\MyTeamController;
use App\Modules\CoreHR\Http\Controllers\PersonalDataController;
use App\Modules\CoreHR\Http\Controllers\PersonController;
use App\Modules\CoreHR\Http\Controllers\PersonnelActionController;
use App\Modules\CoreHR\Http\Controllers\ProfileChangeRequestController;
use App\Modules\CoreHR\Http\Controllers\SocialSecurityController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // ESS-2 · Mi perfil: solo la relación propia (RN-ESS-01).
    Route::get('/me/employment', MeEmploymentController::class);
    // COL-12 · MSS-1 · Inicio según el rol (tablero de RRHH o del manager) y MSS-2 · Mi equipo.
    Route::get('/home', [HomeController::class, 'index']);
    Route::get('/home/hr', [HomeController::class, 'hr'])->middleware('permission:people.employments.view');
    Route::get('/home/manager', [HomeController::class, 'manager'])->middleware('permission:people.team.view');
    Route::get('/my-team', [MyTeamController::class, 'index'])->middleware('permission:people.team.view');
    Route::get('/my-team/key-dates', [MyTeamController::class, 'keyDates'])->middleware('permission:people.team.view');

    // COL-1 · Personas y deduplicación por documento.
    Route::get('/people', [PersonController::class, 'index'])->middleware('permission:people.people.view');
    Route::post('/people', [PersonController::class, 'store'])->middleware('permission:people.people.create');
    Route::get('/people/document-match', [PersonController::class, 'documentMatch'])->middleware('permission:people.people.view');
    Route::get('/people/{id}', [PersonController::class, 'show'])->middleware('permission:people.people.view');
    Route::patch('/people/{id}', [PersonController::class, 'update'])->middleware('permission:people.people.update');

    // COL-2 · COL-9 · Colaboradores: ingreso, listado, ficha básica y acciones de ME-01.
    // Catálogos de estructura (sin datos de personas): también los usa el jefe al proponer un movimiento desde Mi equipo.
    Route::get('/employment-options', [EmploymentController::class, 'options'])->middleware('permission:people.employments.view');
    Route::get('/employments', [EmploymentController::class, 'index'])->middleware('permission:people.employments.view');
    Route::post('/employments', [EmploymentController::class, 'store'])->middleware('permission:people.employments.create');
    Route::get('/employments/{id}', [EmploymentController::class, 'show'])->middleware('permission:people.employments.view');
    Route::patch('/employments/{id}', [EmploymentController::class, 'update'])->middleware('permission:people.employments.update');
    Route::post('/employments/{id}/activate', [EmploymentController::class, 'activate'])->middleware('permission:people.employments.activate');
    Route::post('/employments/{id}/cancel', [EmploymentController::class, 'cancel'])->middleware('permission:people.employments.update');

    // COL-11 · Cese de la relación laboral (ME-01): registrar, revertir y motivos (Tabla 17).
    Route::get('/termination-reasons', [EmploymentController::class, 'terminationReasons'])->middleware('permission:people.employments.terminate');
    Route::post('/employments/{id}/terminate', [EmploymentController::class, 'terminate'])->middleware('permission:people.employments.terminate');
    Route::post('/employments/{id}/cancel-termination', [EmploymentController::class, 'cancelTermination'])->middleware('permission:people.employments.cancel_termination');

    // COL-3 · COL-5 · Ficha 360: cabecera, datos laborales vigentes e historia de la asignación (movimientos).
    Route::get('/employments/{id}/profile', [EmploymentProfileController::class, 'profile'])->middleware('permission:people.employments.view');
    Route::get('/employments/{id}/work-data', [EmploymentProfileController::class, 'workData'])->middleware('permission:people.employments.view');
    // HIS-5 · Línea de tiempo laboral (cada tipo exige además el permiso de su recurso).
    Route::get('/employments/{id}/timeline', [EmploymentProfileController::class, 'timeline'])->middleware('permission:people.employments.view');
    Route::get('/employments/{employment_id}/assignments', [EmploymentProfileController::class, 'assignments'])->middleware('permission:people.assignments.view');
    // HIS-2 · HIS-4 · Movimientos de personal: única vía para cambiar la asignación (con aprobación,
    // programados a futuro, corregidos). El jefe propone por /propose (siempre pasa por aprobación).
    Route::get('/employments/{employment_id}/personnel-actions', [PersonnelActionController::class, 'forEmployment'])->middleware('permission:people.assignments.view');
    Route::post('/employments/{employment_id}/personnel-actions', [PersonnelActionController::class, 'store'])->middleware('permission:people.assignments.create');
    Route::post('/employments/{employment_id}/personnel-actions/propose', [PersonnelActionController::class, 'propose'])->middleware('permission:people.assignments.propose');
    Route::get('/personnel-actions', [PersonnelActionController::class, 'index'])->middleware('permission:people.assignments.view');
    Route::get('/personnel-actions/{id}', [PersonnelActionController::class, 'show'])->middleware('permission:people.assignments.view');
    Route::patch('/personnel-actions/{id}', [PersonnelActionController::class, 'update'])->middleware('permission:people.assignments.create');
    Route::post('/personnel-actions/{id}/cancel', [PersonnelActionController::class, 'cancel'])->middleware('permission:people.assignments.cancel');
    Route::post('/personnel-actions/{id}/correct', [PersonnelActionController::class, 'correct'])->middleware('permission:people.assignments.correct');
    Route::get('/employments/{employment_id}/assignments/{id}', [EmploymentProfileController::class, 'assignment'])->middleware('permission:people.assignments.view');

    // COL-4 · Datos personales de la persona: foto, direcciones, contactos de emergencia y datos sensibles.
    Route::get('/people/{id}/photo', [PersonalDataController::class, 'photo'])->middleware('permission:people.people.view');
    Route::post('/people/{id}/photo', [PersonalDataController::class, 'uploadPhoto'])->middleware('permission:people.people.update');
    Route::delete('/people/{id}/photo', [PersonalDataController::class, 'removePhoto'])->middleware('permission:people.people.update');
    Route::get('/people/{id}/addresses', [PersonalDataController::class, 'addresses'])->middleware('permission:people.people.view');
    Route::post('/people/{id}/addresses', [PersonalDataController::class, 'storeAddress'])->middleware('permission:people.people.update');
    Route::patch('/people/{id}/addresses/{address_id}', [PersonalDataController::class, 'updateAddress'])->middleware('permission:people.people.update');
    Route::delete('/people/{id}/addresses/{address_id}', [PersonalDataController::class, 'deleteAddress'])->middleware('permission:people.people.update');
    Route::get('/people/{id}/emergency-contacts', [PersonalDataController::class, 'contacts'])->middleware('permission:people.people.view');
    Route::post('/people/{id}/emergency-contacts', [PersonalDataController::class, 'storeContact'])->middleware('permission:people.people.update');
    Route::patch('/people/{id}/emergency-contacts/{contact_id}', [PersonalDataController::class, 'updateContact'])->middleware('permission:people.people.update');
    Route::delete('/people/{id}/emergency-contacts/{contact_id}', [PersonalDataController::class, 'deleteContact'])->middleware('permission:people.people.update');
    Route::get('/people/{id}/sensitive-data', [PersonalDataController::class, 'sensitiveData'])->middleware('permission:people.people.view');
    Route::put('/people/{id}/sensitive-data', [PersonalDataController::class, 'saveSensitiveData'])->middleware('permission:people.people.update');

    // COL-4 · RN-COL-06 · Cambios de datos propuestos por el propio colaborador.
    Route::get('/profile-change-requests', [ProfileChangeRequestController::class, 'index'])->middleware('permission:people.change_requests.view');
    Route::post('/profile-change-requests', [ProfileChangeRequestController::class, 'store'])->middleware('permission:people.change_requests.create');
    Route::get('/profile-change-requests/{id}', [ProfileChangeRequestController::class, 'show'])->middleware('permission:people.change_requests.view');
    Route::post('/profile-change-requests/{id}/approve', [ProfileChangeRequestController::class, 'approve'])->middleware('permission:people.change_requests.approve');
    Route::post('/profile-change-requests/{id}/reject', [ProfileChangeRequestController::class, 'reject'])->middleware('permission:people.change_requests.reject');
    Route::post('/profile-change-requests/{id}/cancel', [ProfileChangeRequestController::class, 'cancel'])->middleware('permission:people.change_requests.cancel');

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

    // HIS-3 · Compensación con vigencia: historia (montos con permiso de campo) y cambios con motivo y aprobación.
    Route::get($emp.'/compensations', [CompensationController::class, 'index'])->middleware('permission:people.compensations.view');
    Route::get($emp.'/compensations/{id}', [CompensationController::class, 'show'])->middleware('permission:people.compensations.view');
    Route::get($emp.'/compensation-changes', [CompensationController::class, 'changes'])->middleware('permission:people.compensations.view');
    Route::post($emp.'/compensation-changes', [CompensationController::class, 'store'])->middleware('permission:people.compensations.create');
    Route::get($emp.'/compensation-changes/{id}', [CompensationController::class, 'showChange'])->middleware('permission:people.compensations.view');
    Route::post($emp.'/compensation-changes/{id}/cancel', [CompensationController::class, 'cancel'])->middleware('permission:people.compensations.cancel');
    Route::get('/compensation-concepts', [CompensationController::class, 'concepts'])->middleware('permission:people.compensations.view');
});
