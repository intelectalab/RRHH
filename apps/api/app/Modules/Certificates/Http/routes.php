<?php

use App\Modules\Certificates\Http\Controllers\CertificateRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // ESS-4 · Constancias: catálogo y solicitud propia; RRHH emite o rechaza las de su alcance.
    Route::get('/certificate-types', [CertificateRequestController::class, 'types'])->middleware('permission:people.certificates.request');
    Route::get('/certificate-requests', [CertificateRequestController::class, 'index'])->middleware('permission:people.certificates.view');
    Route::post('/certificate-requests', [CertificateRequestController::class, 'store'])->middleware('permission:people.certificates.request');
    Route::get('/certificate-requests/{id}', [CertificateRequestController::class, 'show'])->middleware('permission:people.certificates.view');
    Route::post('/certificate-requests/{id}/issue', [CertificateRequestController::class, 'issue'])->middleware('permission:people.certificates.issue');
    Route::post('/certificate-requests/{id}/reject', [CertificateRequestController::class, 'reject'])->middleware('permission:people.certificates.issue');
    Route::get('/certificate-requests/{id}/file', [CertificateRequestController::class, 'file'])->middleware('permission:people.certificates.view');
});
