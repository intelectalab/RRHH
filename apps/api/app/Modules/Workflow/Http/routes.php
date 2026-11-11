<?php

use App\Modules\Workflow\Http\Controllers\ApprovalFlowController;
use App\Modules\Workflow\Http\Controllers\ApprovalRequestController;
use App\Modules\Workflow\Http\Controllers\AutomationRuleController;
use App\Modules\Workflow\Http\Controllers\AutomationRunController;
use App\Modules\Workflow\Http\Controllers\TaskController;
use App\Shared\Inbox\InboxController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/approval-flows', [ApprovalFlowController::class, 'index'])->middleware('permission:workflow.approval_flows.view');
    Route::post('/approval-flows', [ApprovalFlowController::class, 'store'])->middleware('permission:workflow.approval_flows.create');
    Route::get('/approval-flows/{id}', [ApprovalFlowController::class, 'show'])->middleware('permission:workflow.approval_flows.view');
    Route::patch('/approval-flows/{id}', [ApprovalFlowController::class, 'update'])->middleware('permission:workflow.approval_flows.update');
    Route::post('/approval-flows/{id}/publish', [ApprovalFlowController::class, 'publish'])->middleware('permission:workflow.approval_flows.publish');
    Route::post('/approval-flows/{id}/retire', [ApprovalFlowController::class, 'retire'])->middleware('permission:workflow.approval_flows.retire');

    Route::get('/approval-requests', [ApprovalRequestController::class, 'index'])->middleware('permission:workflow.approval_requests.view');
    Route::post('/approval-requests/bulk-approve', [ApprovalRequestController::class, 'bulkApprove'])->middleware('permission:workflow.approval_requests.approve');
    Route::get('/approval-requests/{id}', [ApprovalRequestController::class, 'show'])->middleware('permission:workflow.approval_requests.view');
    Route::post('/approval-requests/{id}/approve', [ApprovalRequestController::class, 'approve'])->middleware('permission:workflow.approval_requests.approve');
    Route::post('/approval-requests/{id}/reject', [ApprovalRequestController::class, 'reject'])->middleware('permission:workflow.approval_requests.reject');
    Route::post('/approval-requests/{id}/delegate', [ApprovalRequestController::class, 'delegate'])->middleware('permission:workflow.approval_requests.delegate');

    Route::get('/tasks', [TaskController::class, 'index'])->middleware('permission:workflow.tasks.view');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('permission:workflow.tasks.create');
    Route::get('/tasks/{id}', [TaskController::class, 'show'])->middleware('permission:workflow.tasks.view');
    Route::patch('/tasks/{id}', [TaskController::class, 'update'])->middleware('permission:workflow.tasks.update');
    Route::post('/tasks/{id}/complete', [TaskController::class, 'complete'])->middleware('permission:workflow.tasks.complete');
    Route::post('/tasks/{id}/cancel', [TaskController::class, 'cancel'])->middleware('permission:workflow.tasks.cancel');

    // WFL-5 · Reglas CUANDO → SI → ENTONCES y su centro de ejecuciones.
    Route::get('/workflows', [AutomationRuleController::class, 'index'])->middleware('permission:workflow.workflows.view');
    Route::post('/workflows', [AutomationRuleController::class, 'store'])->middleware('permission:workflow.workflows.create');
    Route::get('/workflows/catalog', [AutomationRuleController::class, 'catalog'])->middleware('permission:workflow.workflows.view');
    Route::get('/workflows/{id}', [AutomationRuleController::class, 'show'])->middleware('permission:workflow.workflows.view');
    Route::patch('/workflows/{id}', [AutomationRuleController::class, 'update'])->middleware('permission:workflow.workflows.update');
    Route::post('/workflows/{id}/activate', [AutomationRuleController::class, 'activate'])->middleware('permission:workflow.workflows.activate');
    Route::post('/workflows/{id}/pause', [AutomationRuleController::class, 'pause'])->middleware('permission:workflow.workflows.pause');
    Route::post('/workflows/{id}/retire', [AutomationRuleController::class, 'retire'])->middleware('permission:workflow.workflows.retire');
    Route::post('/workflows/{id}/test', [AutomationRuleController::class, 'test'])->middleware('permission:workflow.workflows.update');

    Route::get('/workflow-runs', [AutomationRunController::class, 'index'])->middleware('permission:workflow.runs.view');
    Route::get('/workflow-runs/{id}', [AutomationRunController::class, 'show'])->middleware('permission:workflow.runs.view');
    Route::post('/workflow-runs/{id}/retry', [AutomationRunController::class, 'retry'])->middleware('permission:workflow.runs.retry');
    Route::post('/workflow-runs/{id}/cancel', [AutomationRunController::class, 'cancel'])->middleware('permission:workflow.runs.cancel');

    Route::get('/inbox', InboxController::class);
});
