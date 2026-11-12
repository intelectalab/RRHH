<?php

use App\Modules\Organization\Http\Controllers\CostCenterController;
use App\Modules\Organization\Http\Controllers\CostDistributionController;
use App\Modules\Organization\Http\Controllers\LegalEntityController;
use App\Modules\Organization\Http\Controllers\OrganizationCatalogController;
use App\Modules\Organization\Http\Controllers\SiteController;
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

    // ORG-2 · Sedes, sucursales y ubicaciones.
    Route::get('/sites', [SiteController::class, 'index'])->middleware('permission:org.sites.view');
    Route::post('/sites', [SiteController::class, 'store'])->middleware('permission:org.sites.create');
    Route::get('/sites/{id}', [SiteController::class, 'show'])->middleware('permission:org.sites.view');
    Route::patch('/sites/{id}', [SiteController::class, 'update'])->middleware('permission:org.sites.update');
    Route::post('/sites/{id}/archive', [SiteController::class, 'archive'])->middleware('permission:org.sites.archive');
    Route::post('/sites/{id}/restore', [SiteController::class, 'restore'])->middleware('permission:org.sites.restore');

    // ORG-4 · Centros de costo y distribución por relación laboral (RN-ORG-06).
    Route::get('/cost-centers', [CostCenterController::class, 'index'])->middleware('permission:org.cost_centers.view');
    Route::post('/cost-centers', [CostCenterController::class, 'store'])->middleware('permission:org.cost_centers.create');
    Route::get('/cost-centers/{id}', [CostCenterController::class, 'show'])->middleware('permission:org.cost_centers.view');
    Route::patch('/cost-centers/{id}', [CostCenterController::class, 'update'])->middleware('permission:org.cost_centers.update');
    Route::post('/cost-centers/{id}/close', [CostCenterController::class, 'close'])->middleware('permission:org.cost_centers.update');
    Route::post('/cost-centers/{id}/reopen', [CostCenterController::class, 'reopen'])->middleware('permission:org.cost_centers.update');
    Route::post('/cost-centers/{id}/archive', [CostCenterController::class, 'archive'])->middleware('permission:org.cost_centers.archive');
    Route::post('/cost-centers/{id}/restore', [CostCenterController::class, 'restore'])->middleware('permission:org.cost_centers.restore');
    Route::get('/employments/{employment_id}/cost-distributions', [CostDistributionController::class, 'index'])->middleware('permission:people.assignments.view');
    Route::post('/employments/{employment_id}/cost-distributions', [CostDistributionController::class, 'store'])->middleware('permission:people.assignments.create');
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
