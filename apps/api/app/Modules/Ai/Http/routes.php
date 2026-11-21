<?php

use App\Modules\Ai\Http\Controllers\AiController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    // IAR-9 · IAR-10 · Asistente de ayuda documental (solo artículos visibles, con citas).
    Route::get('/ai/help-assistant/availability', [AiController::class, 'availability']);
    Route::post('/ai/help-assistant/ask', [AiController::class, 'ask'])->middleware('permission:ai.help_assistant.use');
    Route::post('/ai/help-assistant/answers/{id}/feedback', [AiController::class, 'feedback'])->middleware('permission:ai.help_assistant.use');
    // IAR-11 · Calidad: preguntas sin respuesta y respuestas mal valoradas.
    Route::get('/ai/help-assistant/quality', [AiController::class, 'quality'])->middleware('permission:ai.usage_logs.view');
    // IAR-10 · Activación por la organización.
    Route::get('/ai/use-case-settings', [AiController::class, 'settingsIndex'])->middleware('permission:ai.use_case_settings.view');
    Route::get('/ai/use-case-settings/{id}', [AiController::class, 'settingsShow'])->middleware('permission:ai.use_case_settings.view');
    Route::patch('/ai/use-case-settings/{id}', [AiController::class, 'settingsUpdate'])->middleware('permission:ai.use_case_settings.update');
    // IAR-2 · Bitácora de uso de IA (metadatos, sin contenido).
    Route::get('/ai/usage-logs', [AiController::class, 'logsIndex'])->middleware('permission:ai.usage_logs.view');
    Route::get('/ai/usage-logs/{id}', [AiController::class, 'logsShow'])->middleware('permission:ai.usage_logs.view');
});
