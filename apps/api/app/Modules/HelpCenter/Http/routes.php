<?php

use App\Modules\HelpCenter\Http\Controllers\HelpCenterController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // AYU-2 · Centro de ayuda (todos los roles): guías por rol y módulo y búsqueda.
    Route::get('/help/articles', [HelpCenterController::class, 'index'])->middleware('permission:help.articles.view');
    Route::get('/help/search', [HelpCenterController::class, 'search'])->middleware('permission:help.articles.view');
    Route::get('/help/articles/{id}', [HelpCenterController::class, 'show'])->middleware('permission:help.articles.view');
    // AYU-3 · Ayuda de una pantalla y sus ayudas de campo.
    Route::get('/help/screens/{screen_key}', [HelpCenterController::class, 'screen'])->middleware('permission:help.articles.view');
});
