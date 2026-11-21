<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Shared\Tenancy\Http\TlsAllowedController;
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

// QA-17 · Consulta interna del proxy HTTPS antes de emitir un certificado. No se publica: Caddy responde 404
// a /api/internal/* desde Internet y solo la llama por la red interna (docker/prod/Caddyfile).
Route::get('/internal/tls/allowed', TlsAllowedController::class);
