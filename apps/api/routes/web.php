<?php

use App\Http\Controllers\Api\DocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['api' => 'Ayni HR', 'version' => '1.0.0']);
});

Route::get('/docs', [DocsController::class, 'index']);
Route::get('/docs/openapi.yaml', [DocsController::class, 'spec']);
