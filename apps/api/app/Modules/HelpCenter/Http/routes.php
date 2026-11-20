<?php

use App\Modules\HelpCenter\Http\Controllers\HelpCenterController;
use App\Modules\HelpCenter\Http\Controllers\OrgArticleController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // AYU-2 · Centro de ayuda (todos los roles): guías por rol y módulo y búsqueda (AYU-5: también en los
    // artículos de la organización, sin tildes ni mayúsculas).
    Route::get('/help/articles', [HelpCenterController::class, 'index'])->middleware('permission:help.articles.view');
    Route::get('/help/search', [HelpCenterController::class, 'search'])->middleware('permission:help.articles.view');
    Route::get('/help/articles/{id}', [HelpCenterController::class, 'show'])->middleware('permission:help.articles.view');
    // AYU-5 · «¿Te sirvió?».
    Route::post('/help/articles/{id}/feedback', [HelpCenterController::class, 'feedback'])->middleware('permission:help.articles.view');
    // AYU-3 · Ayuda de una pantalla y sus ayudas de campo.
    Route::get('/help/screens/{screen_key}', [HelpCenterController::class, 'screen'])->middleware('permission:help.articles.view');
    // AYU-7 · Recorridos por rol y novedades de versión.
    Route::get('/help/tours', [HelpCenterController::class, 'tours'])->middleware('permission:help.articles.view');
    Route::post('/help/tours/{tour_key}/complete', [HelpCenterController::class, 'completeTour'])->middleware('permission:help.articles.view');
    Route::get('/help/whats-new', [HelpCenterController::class, 'whatsNew'])->middleware('permission:help.articles.view');
    // AYU-6 · Consultas derivadas a RRHH (o al soporte de GestiaHR, solo el administrador).
    Route::get('/help/escalations', [HelpCenterController::class, 'escalations'])->middleware('permission:help.escalations.view');
    Route::post('/help/escalations', [HelpCenterController::class, 'escalate'])->middleware('permission:help.escalations.create');
    // AYU-5 · Uso de la ayuda en la organización.
    Route::get('/help/insights', [HelpCenterController::class, 'insights'])->middleware('permission:help.insights.view');

    // AYU-4 · Artículos y políticas de la organización.
    Route::get('/help/org-articles', [OrgArticleController::class, 'index'])->middleware('permission:help.org_articles.view');
    Route::post('/help/org-articles', [OrgArticleController::class, 'store'])->middleware('permission:help.org_articles.create');
    Route::get('/help/org-articles/{id}', [OrgArticleController::class, 'show'])->middleware('permission:help.org_articles.view');
    Route::patch('/help/org-articles/{id}', [OrgArticleController::class, 'update'])->middleware('permission:help.org_articles.update');
    Route::post('/help/org-articles/{id}/publish', [OrgArticleController::class, 'publish'])->middleware('permission:help.org_articles.publish');
    Route::post('/help/org-articles/{id}/archive', [OrgArticleController::class, 'archive'])->middleware('permission:help.org_articles.archive');
});
