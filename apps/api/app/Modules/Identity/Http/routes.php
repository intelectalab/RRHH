<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware('api')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:ayni-login');
    Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:ayni-login');
    Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:ayni-login');

    Route::middleware('auth:ayni')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });
});
