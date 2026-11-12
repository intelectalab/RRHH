<?php

use App\Modules\Organization\Http\Controllers\LegalEntityController;
use App\Modules\Organization\Http\Controllers\OrganizationCatalogController;
use App\Modules\Organization\Http\Controllers\OrgUnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/catalogs/organization', OrganizationCatalogController::class)->middleware('permission:org.legal_entities.view');
    Route::get('/legal-entities', [LegalEntityController::class, 'index'])->middleware('permission:org.legal_entities.view');
    Route::post('/legal-entities', [LegalEntityController::class, 'store'])->middleware('permission:org.legal_entities.create');
    Route::get('/legal-entities/{id}', [LegalEntityController::class, 'show'])->middleware('permission:org.legal_entities.view');
    Route::patch('/legal-entities/{id}', [LegalEntityController::class, 'update'])->middleware('permission:org.legal_entities.update');
    Route::post('/legal-entities/{id}/archive', [LegalEntityController::class, 'archive'])->middleware('permission:org.legal_entities.archive');
    Route::post('/legal-entities/{id}/restore', [LegalEntityController::class, 'restore'])->middleware('permission:org.legal_entities.restore');
    // CFG-2 · Logo de la empresa.
    Route::get('/legal-entities/{id}/logo', [LegalEntityController::class, 'logo'])->middleware('permission:org.legal_entities.view');
    Route::post('/legal-entities/{id}/logo', [LegalEntityController::class, 'uploadLogo'])->middleware('permission:org.legal_entities.update');
    Route::delete('/legal-entities/{id}/logo', [LegalEntityController::class, 'removeLogo'])->middleware('permission:org.legal_entities.update');

    // ORG-3 · Árbol de unidades organizacionales con vigencia.
    Route::get('/org-unit-types', [OrgUnitController::class, 'types'])->middleware('permission:org.units.view');
    Route::post('/org-unit-types', [OrgUnitController::class, 'storeType'])->middleware('permission:org.units.update');
    Route::patch('/org-unit-types/{id}', [OrgUnitController::class, 'updateType'])->middleware('permission:org.units.update');
    Route::get('/org-unit-options', [OrgUnitController::class, 'options'])->middleware('permission:org.units.view');
    Route::get('/org-units', [OrgUnitController::class, 'index'])->middleware('permission:org.units.view');
    Route::post('/org-units', [OrgUnitController::class, 'store'])->middleware('permission:org.units.create');
    Route::get('/org-units/{id}', [OrgUnitController::class, 'show'])->middleware('permission:org.units.view');
    Route::patch('/org-units/{id}', [OrgUnitController::class, 'update'])->middleware('permission:org.units.update');
    Route::get('/org-units/{id}/history', [OrgUnitController::class, 'history'])->middleware('permission:org.units.view');
    Route::get('/org-units/{id}/impact', [OrgUnitController::class, 'impact'])->middleware('permission:org.units.view');
    Route::post('/org-units/{id}/move', [OrgUnitController::class, 'move'])->middleware('permission:org.units.move');
    Route::post('/org-units/{id}/archive', [OrgUnitController::class, 'archive'])->middleware('permission:org.units.archive');
    Route::post('/org-units/{id}/restore', [OrgUnitController::class, 'restore'])->middleware('permission:org.units.restore');
});
