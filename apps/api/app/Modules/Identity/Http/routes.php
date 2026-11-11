<?php

use App\Modules\Identity\Http\Controllers\AccountSecurityController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\CodeSignInController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\MfaController;
use App\Modules\Identity\Http\Controllers\PermissionCatalogController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use App\Shared\Search\SearchController;
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
    Route::post('/auth/invitation/accept', [AuthController::class, 'acceptInvitation'])->middleware('throttle:ayni-login');
    Route::post('/auth/otp/request', [CodeSignInController::class, 'request'])->middleware('throttle:ayni-login');
    Route::post('/auth/otp/verify', [CodeSignInController::class, 'verify'])->middleware('throttle:ayni-login');
    Route::post('/auth/activation/code', [CodeSignInController::class, 'activate'])->middleware('throttle:ayni-login');

    Route::middleware('auth:ayni')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', MeController::class);
        Route::get('/search', SearchController::class);
        Route::get('/me/sessions', [AccountSecurityController::class, 'sessions']);
        Route::post('/me/sessions/{id}/revoke', [AccountSecurityController::class, 'revokeSession']);
        Route::post('/me/password', [AccountSecurityController::class, 'changePassword'])->middleware('throttle:ayni-login');
        Route::post('/auth/mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:ayni-login');
        Route::get('/me/mfa', [MfaController::class, 'show']);
        Route::post('/me/mfa/totp', [MfaController::class, 'startTotp']);
        Route::post('/me/mfa/totp/confirm', [MfaController::class, 'confirmTotp'])->middleware('throttle:ayni-login');
        Route::post('/me/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:ayni-login');
        Route::post('/me/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:ayni-login');

        Route::get('/permissions', PermissionCatalogController::class)->middleware('permission:identity.roles.view');

        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:identity.roles.view');
        Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:identity.roles.create');
        Route::get('/roles/{id}', [RoleController::class, 'show'])->middleware('permission:identity.roles.view');
        Route::patch('/roles/{id}', [RoleController::class, 'update'])->middleware('permission:identity.roles.update');
        Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:identity.roles.delete');
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:identity.users.view');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:identity.users.create');
        Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:identity.users.view');
        Route::patch('/users/{id}', [UserController::class, 'update'])->middleware('permission:identity.users.update');
        Route::post('/users/{id}/invite', [UserController::class, 'invite'])->middleware('permission:identity.users.invite');
        Route::post('/users/{id}/activation-code', [UserController::class, 'activationCode'])->middleware('permission:identity.users.invite');
        Route::post('/users/{id}/suspend', [UserController::class, 'suspend'])->middleware('permission:identity.users.suspend');
        Route::post('/users/{id}/reactivate', [UserController::class, 'reactivate'])->middleware('permission:identity.users.reactivate');
        Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:identity.users.reset_password');
        Route::post('/users/{id}/revoke-sessions', [UserController::class, 'revokeSessions'])->middleware('permission:identity.users.suspend');
        Route::post('/users/{id}/reset-mfa', [UserController::class, 'resetMfa'])->middleware('permission:identity.users.reset_mfa');

        Route::post('/roles/{id}/mfa-requirement', [RoleController::class, 'mfaRequirement'])->middleware('permission:identity.security_policies.update');
        Route::post('/roles/{id}/duplicate', [RoleController::class, 'duplicate'])->middleware('permission:identity.roles.duplicate');
    });
});
