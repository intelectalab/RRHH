<?php

use App\Modules\SelfService\Http\Controllers\MeHomeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // ESS-1 · Inicio del colaborador: solo datos propios (RN-ESS-01), tarjetas de módulos contratados (RN-ESS-02).
    Route::get('/me/home', MeHomeController::class);
});
