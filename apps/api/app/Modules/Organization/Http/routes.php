<?php

use App\Modules\Organization\Http\Controllers\CostCenterController;
use App\Modules\Organization\Http\Controllers\CostDistributionController;
use App\Modules\Organization\Http\Controllers\DirectoryController;
use App\Modules\Organization\Http\Controllers\HeadcountController;
use App\Modules\Organization\Http\Controllers\JobCatalogController;
use App\Modules\Organization\Http\Controllers\JobProfileController;
use App\Modules\Organization\Http\Controllers\LegalEntityController;
use App\Modules\Organization\Http\Controllers\OrganizationCatalogController;
use App\Modules\Organization\Http\Controllers\OrgUnitController;
use App\Modules\Organization\Http\Controllers\PositionController;
use App\Modules\Organization\Http\Controllers\PositionRequestController;
use App\Modules\Organization\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // ORG-6 · ORG-7 · Organigrama a una fecha y directorio de personas (RN-ORG-08).
    Route::get('/org-chart', [DirectoryController::class, 'orgChart'])->middleware('permission:people.directory.view');
    Route::get('/directory', [DirectoryController::class, 'index'])->middleware('permission:people.directory.view');
    Route::get('/directory/{employment_id}', [DirectoryController::class, 'show'])->middleware('permission:people.directory.view');

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
    // ORG-1 · Historia del régimen laboral de la empresa con vigencia.
    Route::get('/legal-entities/{id}/regimes', [LegalEntityController::class, 'regimes'])->middleware('permission:org.legal_entities.view');

    // ORG-5 · Catálogo de puestos y sus catálogos configurables (familias, niveles y categorías).
    Route::get('/job-profiles', [JobProfileController::class, 'index'])->middleware('permission:org.job_profiles.view');
    Route::post('/job-profiles', [JobProfileController::class, 'store'])->middleware('permission:org.job_profiles.create');
    Route::get('/job-profiles/{id}', [JobProfileController::class, 'show'])->middleware('permission:org.job_profiles.view');
    Route::patch('/job-profiles/{id}', [JobProfileController::class, 'update'])->middleware('permission:org.job_profiles.update');
    Route::post('/job-profiles/{id}/archive', [JobProfileController::class, 'archive'])->middleware('permission:org.job_profiles.archive');
    Route::post('/job-profiles/{id}/restore', [JobProfileController::class, 'restore'])->middleware('permission:org.job_profiles.restore');
    Route::get('/job-profiles/{id}/versions', [JobProfileController::class, 'versions'])->middleware('permission:org.job_profiles.view');
    foreach (['job-families' => 'family', 'job-levels' => 'level', 'job-categories' => 'category'] as $path => $kind) {
        Route::get("/{$path}", [JobCatalogController::class, 'index'])->defaults('kind', $kind)->middleware('permission:org.job_profiles.view');
        Route::post("/{$path}", [JobCatalogController::class, 'store'])->defaults('kind', $kind)->middleware('permission:org.job_profiles.create');
        Route::get("/{$path}/{id}", [JobCatalogController::class, 'show'])->defaults('kind', $kind)->middleware('permission:org.job_profiles.view');
        Route::patch("/{$path}/{id}", [JobCatalogController::class, 'update'])->defaults('kind', $kind)->middleware('permission:org.job_profiles.update');
        Route::post("/{$path}/{id}/archive", [JobCatalogController::class, 'archive'])->defaults('kind', $kind)->middleware('permission:org.job_profiles.archive');
        Route::post("/{$path}/{id}/restore", [JobCatalogController::class, 'restore'])->defaults('kind', $kind)->middleware('permission:org.job_profiles.restore');
    }

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

    // POS-1 · POS-2 · Posiciones como plazas y sus estados (ME-03).
    Route::get('/position-options', [PositionController::class, 'options'])->middleware('permission:org.positions.view');
    Route::get('/positions', [PositionController::class, 'index'])->middleware('permission:org.positions.view');
    Route::post('/positions', [PositionController::class, 'store'])->middleware('permission:org.positions.create');
    Route::get('/positions/{id}', [PositionController::class, 'show'])->middleware('permission:org.positions.view');
    Route::patch('/positions/{id}', [PositionController::class, 'update'])->middleware('permission:org.positions.update');
    Route::get('/positions/{id}/occupants', [PositionController::class, 'occupants'])->middleware('permission:org.positions.view');
    Route::get('/positions/{id}/status-events', [PositionController::class, 'statusEvents'])->middleware('permission:org.positions.view');
    Route::get('/positions/{id}/reporting-line', [PositionController::class, 'reportingLine'])->middleware('permission:org.positions.view');
    Route::post('/positions/{id}/approve', [PositionController::class, 'approve'])->middleware('permission:org.positions.approve');
    Route::post('/positions/{id}/reject', [PositionController::class, 'reject'])->middleware('permission:org.positions.approve');
    Route::post('/positions/{id}/open-recruitment', [PositionController::class, 'openRecruitment'])->middleware('permission:org.positions.update');
    Route::post('/positions/{id}/cancel-recruitment', [PositionController::class, 'cancelRecruitment'])->middleware('permission:org.positions.update');
    Route::post('/positions/{id}/freeze', [PositionController::class, 'freeze'])->middleware('permission:org.positions.freeze');
    Route::post('/positions/{id}/unfreeze', [PositionController::class, 'unfreeze'])->middleware('permission:org.positions.unfreeze');
    Route::post('/positions/{id}/close', [PositionController::class, 'close'])->middleware('permission:org.positions.close');
    // POS-3 · Solicitudes de posición (se aprueban en el centro de aprobaciones).
    Route::get('/position-requests', [PositionRequestController::class, 'index'])->middleware('permission:org.position_requests.view');
    Route::post('/position-requests', [PositionRequestController::class, 'store'])->middleware('permission:org.position_requests.create');
    Route::get('/position-requests/{id}', [PositionRequestController::class, 'show'])->middleware('permission:org.position_requests.view');
    Route::patch('/position-requests/{id}', [PositionRequestController::class, 'update'])->middleware('permission:org.position_requests.create');
    Route::post('/position-requests/{id}/submit', [PositionRequestController::class, 'submit'])->middleware('permission:org.position_requests.create');
    Route::post('/position-requests/{id}/cancel', [PositionRequestController::class, 'cancel'])->middleware('permission:org.position_requests.create');
    // POS-4 · Tablero de dotación, desviación por posición y presupuestos por unidad.
    Route::get('/headcount', [HeadcountController::class, 'index'])->middleware('permission:org.positions.view');
    Route::get('/headcount/positions', [HeadcountController::class, 'positions'])->middleware('permission:org.positions.view');
    Route::get('/headcount-budgets', [HeadcountController::class, 'budgets'])->middleware('permission:org.headcount_plans.view');
    Route::post('/headcount-budgets', [HeadcountController::class, 'storeBudget'])->middleware('permission:org.headcount_plans.create');
    Route::get('/headcount-budgets/{id}', [HeadcountController::class, 'showBudget'])->middleware('permission:org.headcount_plans.view');
    Route::patch('/headcount-budgets/{id}', [HeadcountController::class, 'updateBudget'])->middleware('permission:org.headcount_plans.update');
});
