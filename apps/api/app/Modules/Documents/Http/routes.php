<?php

use App\Modules\Documents\Http\Controllers\DocumentCategoryController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\EmploymentDocumentsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // DOC-1 · Carpetas del legajo y tipos documentales.
    Route::get('/document-categories', [DocumentCategoryController::class, 'index'])->middleware('permission:documents.documents.view');
    Route::post('/document-categories', [DocumentCategoryController::class, 'store'])->middleware('permission:documents.document_types.update');
    Route::patch('/document-categories/{id}', [DocumentCategoryController::class, 'update'])->middleware('permission:documents.document_types.update');
    Route::post('/document-categories/{id}/archive', [DocumentCategoryController::class, 'archive'])->middleware('permission:documents.document_types.update');
    Route::post('/document-categories/{id}/restore', [DocumentCategoryController::class, 'restore'])->middleware('permission:documents.document_types.update');

    // DOC-1 · DOC-2 · Documentos del legajo (ME-12): subir, revisar, versiones, archivo y vencimientos.
    Route::get('/documents', [DocumentController::class, 'index'])->middleware('permission:documents.documents.view');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('permission:documents.documents.create');
    // DOC-2 · Por vencer y vencidos (antes de /documents/{id}).
    Route::get('/documents/expiring', [DocumentController::class, 'expiring'])->middleware('permission:documents.documents.view');
    Route::get('/documents/{id}', [DocumentController::class, 'show'])->middleware('permission:documents.documents.view');
    Route::patch('/documents/{id}', [DocumentController::class, 'update'])->middleware('permission:documents.documents.update');
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy'])->middleware('permission:documents.documents.update');
    Route::post('/documents/{id}/approve', [DocumentController::class, 'approve'])->middleware('permission:documents.documents.approve');
    Route::post('/documents/{id}/reject', [DocumentController::class, 'reject'])->middleware('permission:documents.documents.approve');
    Route::post('/documents/{id}/archive', [DocumentController::class, 'archive'])->middleware('permission:documents.documents.archive');
    Route::post('/documents/{id}/restore', [DocumentController::class, 'restore'])->middleware('permission:documents.documents.restore');
    Route::get('/documents/{id}/versions', [DocumentController::class, 'versions'])->middleware('permission:documents.documents.view');
    Route::post('/documents/{id}/versions', [DocumentController::class, 'addVersion'])->middleware('permission:documents.documents.new_version');
    Route::get('/documents/{id}/file', [DocumentController::class, 'file'])->middleware('permission:documents.documents.view');
    Route::get('/documents/{id}/versions/{version}/file', [DocumentController::class, 'versionFile'])->middleware('permission:documents.documents.view');

    // DOC-1 · Legajo de la relación laboral por carpeta, con los documentos que aportan otros módulos.
    Route::get('/employments/{employment_id}/documents', [EmploymentDocumentsController::class, 'index'])->middleware('permission:documents.documents.view');
    Route::get('/employments/{employment_id}/linked-documents/{ref}/file', [EmploymentDocumentsController::class, 'linkedFile'])->middleware('permission:documents.documents.view');
});
