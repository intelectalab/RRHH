<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'GestiaHR API',
        'version' => 'v1',
        'status' => 'operational',
    ]);
});

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);
});
