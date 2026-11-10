<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\PermissionCatalogController;
use App\Modules\Identity\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

/*
 * Cada ruta declara el permiso de openapi.yaml (x-permission):
 *  - público: sin auth
 *  - authenticated: auth:ayni
 *  - otro: auth:ayni + permission:<código>
 * PermissionDeclarationTest compara estas rutas contra el contrato.
 */
Route::prefix('api/v1')->middleware('api')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:ayni-login');
    Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:ayni-login');
    Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:ayni-login');

    Route::middleware('auth:ayni')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', MeController::class);

        Route::get('/permissions', PermissionCatalogController::class)->middleware('permission:identity.roles.view');

        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:identity.roles.view');
        Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:identity.roles.create');
        Route::get('/roles/{id}', [RoleController::class, 'show'])->middleware('permission:identity.roles.view');
        Route::patch('/roles/{id}', [RoleController::class, 'update'])->middleware('permission:identity.roles.update');
        Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:identity.roles.delete');
        Route::post('/roles/{id}/duplicate', [RoleController::class, 'duplicate'])->middleware('permission:identity.roles.duplicate');
    });
});
