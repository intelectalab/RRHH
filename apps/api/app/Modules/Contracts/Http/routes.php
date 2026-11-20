<?php

use App\Modules\Contracts\Http\Controllers\ContractAddendumController;
use App\Modules\Contracts\Http\Controllers\ContractController;
use App\Modules\Contracts\Http\Controllers\ContractTypeController;
use App\Modules\Contracts\Http\Controllers\RemoteWorkController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/contract-types', [ContractTypeController::class, 'index'])->middleware('permission:contracts.contract_types.view');
    Route::post('/contract-types', [ContractTypeController::class, 'store'])->middleware('permission:contracts.contract_types.create');
    Route::get('/contract-types/{id}', [ContractTypeController::class, 'show'])->middleware('permission:contracts.contract_types.view');
    Route::patch('/contract-types/{id}', [ContractTypeController::class, 'update'])->middleware('permission:contracts.contract_types.update');

    // CON-2 · CON-3 · Contrato de la relación laboral (ME-11): registro, documento, firma y renovación.
    Route::get('/contracts', [ContractController::class, 'index'])->middleware('permission:contracts.contracts.view');
    // CON-4 · Por vencer y vencidos sin regularizar (antes de /contracts/{id}).
    Route::get('/contracts/expiring', [ContractController::class, 'expiring'])->middleware('permission:contracts.contracts.view');
    Route::post('/contracts', [ContractController::class, 'store'])->middleware('permission:contracts.contracts.create');
    Route::get('/contracts/{id}', [ContractController::class, 'show'])->middleware('permission:contracts.contracts.view');
    Route::patch('/contracts/{id}', [ContractController::class, 'update'])->middleware('permission:contracts.contracts.update');
    Route::post('/contracts/{id}/submit', [ContractController::class, 'submit'])->middleware('permission:contracts.contracts.create');
    Route::post('/contracts/{id}/approve', [ContractController::class, 'approve'])->middleware('permission:contracts.contracts.approve');
    Route::post('/contracts/{id}/return', [ContractController::class, 'returnToDraft'])->middleware('permission:contracts.contracts.approve');
    Route::post('/contracts/{id}/register-signature', [ContractController::class, 'registerSignature'])->middleware('permission:contracts.contracts.issue');
    Route::post('/contracts/{id}/decline-signature', [ContractController::class, 'declineSignature'])->middleware('permission:contracts.contracts.issue');
    Route::post('/contracts/{id}/void', [ContractController::class, 'void'])->middleware('permission:contracts.contracts.update');
    Route::post('/contracts/{id}/terminate', [ContractController::class, 'terminate'])->middleware('permission:contracts.contracts.terminate');
    Route::post('/contracts/{id}/renew', [ContractController::class, 'renew'])->middleware('permission:contracts.contracts.renew');
    Route::get('/contracts/{id}/document', [ContractController::class, 'document'])->middleware('permission:contracts.contracts.view');
    Route::get('/employments/{employment_id}/contracts', [ContractController::class, 'chain'])->middleware('permission:contracts.contracts.view');

    // CON-3 · Adendas: se generan, se firman y, firmadas, aplican sus cambios con vigencia (RN-CON-08).
    Route::get('/contract-addenda', [ContractAddendumController::class, 'index'])->middleware('permission:contracts.addenda.view');
    Route::post('/contract-addenda', [ContractAddendumController::class, 'store'])->middleware('permission:contracts.addenda.create');
    Route::get('/contract-addenda/{id}', [ContractAddendumController::class, 'show'])->middleware('permission:contracts.addenda.view');
    Route::post('/contract-addenda/{id}/issue', [ContractAddendumController::class, 'issue'])->middleware('permission:contracts.addenda.issue');
    Route::post('/contract-addenda/{id}/register-signature', [ContractAddendumController::class, 'registerSignature'])->middleware('permission:contracts.addenda.issue');
    Route::post('/contract-addenda/{id}/cancel', [ContractAddendumController::class, 'cancel'])->middleware('permission:contracts.addenda.cancel');
    Route::get('/contract-addenda/{id}/document', [ContractAddendumController::class, 'document'])->middleware('permission:contracts.addenda.view');

    // CON-6 · Teletrabajo: solicitud con plazo de respuesta y acuerdo con su contenido mínimo (RN-CON-10).
    Route::get('/remote-work-agreements', [RemoteWorkController::class, 'index'])->middleware('permission:contracts.remote_work.view');
    Route::post('/remote-work-agreements', [RemoteWorkController::class, 'store'])->middleware('permission:contracts.remote_work.create');
    Route::get('/remote-work-agreements/{id}', [RemoteWorkController::class, 'show'])->middleware('permission:contracts.remote_work.view');
    Route::patch('/remote-work-agreements/{id}', [RemoteWorkController::class, 'update'])->middleware('permission:contracts.remote_work.update');
    Route::post('/remote-work-agreements/{id}/approve', [RemoteWorkController::class, 'approve'])->middleware('permission:contracts.remote_work.decide');
    Route::post('/remote-work-agreements/{id}/reject', [RemoteWorkController::class, 'reject'])->middleware('permission:contracts.remote_work.decide');
    Route::post('/remote-work-agreements/{id}/issue', [RemoteWorkController::class, 'issue'])->middleware('permission:contracts.remote_work.issue');
    Route::post('/remote-work-agreements/{id}/register-signature', [RemoteWorkController::class, 'registerSignature'])->middleware('permission:contracts.remote_work.issue');
    Route::post('/remote-work-agreements/{id}/end', [RemoteWorkController::class, 'end'])->middleware('permission:contracts.remote_work.issue');
    Route::get('/remote-work-agreements/{id}/document', [RemoteWorkController::class, 'document'])->middleware('permission:contracts.remote_work.view');
});
