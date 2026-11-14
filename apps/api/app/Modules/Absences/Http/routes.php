<?php

use App\Modules\Absences\Http\Controllers\AbsenceRequestController;
use App\Modules\Absences\Http\Controllers\AbsenceSubsidyController;
use App\Modules\Absences\Http\Controllers\AbsenceTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/absence-types', [AbsenceTypeController::class, 'index'])->middleware('permission:time.absence_types.view');
    Route::post('/absence-types', [AbsenceTypeController::class, 'store'])->middleware('permission:time.absence_types.create');
    Route::get('/absence-types/{id}', [AbsenceTypeController::class, 'show'])->middleware('permission:time.absence_types.view');
    Route::patch('/absence-types/{id}', [AbsenceTypeController::class, 'update'])->middleware('permission:time.absence_types.update');
    Route::get('/absence-types/{id}/rules', [AbsenceTypeController::class, 'rules'])->middleware('permission:time.absence_types.view');
    Route::post('/absence-types/{id}/archive', [AbsenceTypeController::class, 'archive'])->middleware('permission:time.absence_types.archive');

    // AUS-2 · Ausencias y permisos (ME-16): aprobar y rechazar los decide el flujo (centro de aprobaciones).
    Route::get('/absences', [AbsenceRequestController::class, 'index'])->middleware('permission:time.absences.view');
    Route::post('/absences', [AbsenceRequestController::class, 'store'])->middleware('permission:time.absences.create');
    Route::post('/absences/simulate', [AbsenceRequestController::class, 'simulate'])->middleware('permission:time.absences.create');
    Route::get('/absences/{id}', [AbsenceRequestController::class, 'show'])->middleware('permission:time.absences.view');
    Route::patch('/absences/{id}', [AbsenceRequestController::class, 'update'])->middleware('permission:time.absences.update');
    Route::post('/absences/{id}/submit', [AbsenceRequestController::class, 'submit'])->middleware('permission:time.absences.submit');
    Route::post('/absences/{id}/register', [AbsenceRequestController::class, 'register'])->middleware('permission:time.absences.create_for_others');
    Route::post('/absences/{id}/cancel', [AbsenceRequestController::class, 'cancel'])->middleware('permission:time.absences.cancel');
    Route::post('/absences/{id}/attachments', [AbsenceRequestController::class, 'attach'])->middleware('permission:time.absences.update');
    Route::get('/absences/{id}/attachments/{attachment_id}', [AbsenceRequestController::class, 'document'])->middleware('permission:time.absences.view');

    // AUS-3 · Descansos médicos y subsidios: certificado (dato de salud), acumulado anual y reembolso.
    Route::get('/absences/{id}/medical-certificate', [AbsenceSubsidyController::class, 'certificate'])->middleware('permission:time.absences.medical_certificate.view');
    Route::put('/absences/{id}/medical-certificate', [AbsenceSubsidyController::class, 'saveCertificate'])->middleware('permission:time.absences.create_for_others');
    Route::get('/employments/{employment_id}/medical-leave-summary', [AbsenceSubsidyController::class, 'summary'])->middleware('permission:time.absences.manage_subsidy');
    Route::get('/absence-subsidies', [AbsenceSubsidyController::class, 'index'])->middleware('permission:time.absences.manage_subsidy');
    Route::get('/absence-subsidies/{id}', [AbsenceSubsidyController::class, 'show'])->middleware('permission:time.absences.manage_subsidy');
    Route::post('/absence-subsidies/{id}/file', [AbsenceSubsidyController::class, 'file'])->middleware('permission:time.absences.manage_subsidy');
    Route::post('/absence-subsidies/{id}/observe', [AbsenceSubsidyController::class, 'observe'])->middleware('permission:time.absences.manage_subsidy');
    Route::post('/absence-subsidies/{id}/approve', [AbsenceSubsidyController::class, 'approve'])->middleware('permission:time.absences.manage_subsidy');
    Route::post('/absence-subsidies/{id}/reject', [AbsenceSubsidyController::class, 'reject'])->middleware('permission:time.absences.manage_subsidy');
    Route::post('/absence-subsidies/{id}/reimburse', [AbsenceSubsidyController::class, 'reimburse'])->middleware('permission:time.absences.manage_subsidy');
});
