<?php

use App\Modules\Workflow\Http\Controllers\ApprovalFlowController;
use App\Modules\Workflow\Http\Controllers\ApprovalRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/approval-flows', [ApprovalFlowController::class, 'index'])->middleware('permission:workflow.approval_flows.view');
    Route::post('/approval-flows', [ApprovalFlowController::class, 'store'])->middleware('permission:workflow.approval_flows.create');
    Route::get('/approval-flows/{id}', [ApprovalFlowController::class, 'show'])->middleware('permission:workflow.approval_flows.view');
    Route::patch('/approval-flows/{id}', [ApprovalFlowController::class, 'update'])->middleware('permission:workflow.approval_flows.update');
    Route::post('/approval-flows/{id}/publish', [ApprovalFlowController::class, 'publish'])->middleware('permission:workflow.approval_flows.publish');
    Route::post('/approval-flows/{id}/retire', [ApprovalFlowController::class, 'retire'])->middleware('permission:workflow.approval_flows.retire');

    Route::get('/approval-requests', [ApprovalRequestController::class, 'index'])->middleware('permission:workflow.approval_requests.view');
    Route::get('/approval-requests/{id}', [ApprovalRequestController::class, 'show'])->middleware('permission:workflow.approval_requests.view');
    Route::post('/approval-requests/{id}/approve', [ApprovalRequestController::class, 'approve'])->middleware('permission:workflow.approval_requests.approve');
    Route::post('/approval-requests/{id}/reject', [ApprovalRequestController::class, 'reject'])->middleware('permission:workflow.approval_requests.reject');
    Route::post('/approval-requests/{id}/delegate', [ApprovalRequestController::class, 'delegate'])->middleware('permission:workflow.approval_requests.delegate');
});
