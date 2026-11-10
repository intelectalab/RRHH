<?php

use App\Modules\Settings\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'groups'])->middleware('permission:settings.parameters.view');
    Route::get('/settings/{group}', [SettingsController::class, 'show'])->middleware('permission:settings.parameters.view');
    Route::put('/settings/{group}', [SettingsController::class, 'update'])->middleware('permission:settings.parameters.update');
});
