<?php

use App\Modules\Helpdesk\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // ESS-5 · Tipos de solicitud a RRHH (configurables).
    Route::get('/ticket-categories', [TicketController::class, 'categories'])->middleware('permission:helpdesk.tickets.create');
    Route::post('/ticket-categories', [TicketController::class, 'storeCategory'])->middleware('permission:helpdesk.settings.update');
    Route::patch('/ticket-categories/{id}', [TicketController::class, 'updateCategory'])->middleware('permission:helpdesk.settings.update');

    // ESS-5 · Solicitudes a RRHH (ME-31): los estados cambian solo por acciones.
    Route::get('/tickets', [TicketController::class, 'index'])->middleware('permission:helpdesk.tickets.view');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('permission:helpdesk.tickets.create');
    Route::get('/tickets/{id}', [TicketController::class, 'show'])->middleware('permission:helpdesk.tickets.view');
    Route::patch('/tickets/{id}', [TicketController::class, 'update'])->middleware('permission:helpdesk.tickets.update');
    Route::post('/tickets/{id}/assign', [TicketController::class, 'assign'])->middleware('permission:helpdesk.tickets.assign');
    Route::post('/tickets/{id}/request-info', [TicketController::class, 'requestInfo'])->middleware('permission:helpdesk.tickets.request_info');
    Route::post('/tickets/{id}/resolve', [TicketController::class, 'resolve'])->middleware('permission:helpdesk.tickets.resolve');
    Route::post('/tickets/{id}/close', [TicketController::class, 'close'])->middleware('permission:helpdesk.tickets.close');
    Route::post('/tickets/{id}/reopen', [TicketController::class, 'reopen'])->middleware('permission:helpdesk.tickets.reopen');
    Route::post('/tickets/{id}/messages', [TicketController::class, 'message'])->middleware('permission:helpdesk.tickets.create');
    Route::get('/tickets/{id}/messages/{message_id}/file', [TicketController::class, 'file'])->middleware('permission:helpdesk.tickets.view');
});
