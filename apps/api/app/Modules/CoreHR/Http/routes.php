<?php

use App\Modules\CoreHR\Http\Controllers\EmploymentController;
use App\Modules\CoreHR\Http\Controllers\EmploymentProfileController;
use App\Modules\CoreHR\Http\Controllers\PersonalDataController;
use App\Modules\CoreHR\Http\Controllers\PersonController;
use App\Modules\CoreHR\Http\Controllers\ProfileChangeRequestController;
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

    // COL-11 · Cese de la relación laboral (ME-01): registrar, revertir y motivos (Tabla 17).
    Route::get('/termination-reasons', [EmploymentController::class, 'terminationReasons'])->middleware('permission:people.employments.terminate');
    Route::post('/employments/{id}/terminate', [EmploymentController::class, 'terminate'])->middleware('permission:people.employments.terminate');
    Route::post('/employments/{id}/cancel-termination', [EmploymentController::class, 'cancelTermination'])->middleware('permission:people.employments.cancel_termination');

    // COL-3 · COL-5 · Ficha 360: cabecera, datos laborales vigentes e historia de la asignación (movimientos).
    Route::get('/employments/{id}/profile', [EmploymentProfileController::class, 'profile'])->middleware('permission:people.employments.view');
    Route::get('/employments/{id}/work-data', [EmploymentProfileController::class, 'workData'])->middleware('permission:people.employments.view');
    Route::get('/employments/{employment_id}/assignments', [EmploymentProfileController::class, 'assignments'])->middleware('permission:people.assignments.view');
    Route::post('/employments/{employment_id}/assignments', [EmploymentProfileController::class, 'storeAssignment'])->middleware('permission:people.assignments.create');
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
});
