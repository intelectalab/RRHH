<?php

use App\Modules\Organization\Http\Controllers\CostCenterController;
use App\Modules\Organization\Http\Controllers\CostDistributionController;
use App\Modules\Organization\Http\Controllers\JobCatalogController;
use App\Modules\Organization\Http\Controllers\JobProfileController;
use App\Modules\Organization\Http\Controllers\LegalEntityController;
use App\Modules\Organization\Http\Controllers\OrganizationCatalogController;
use App\Modules\Organization\Http\Controllers\SiteController;
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
});
