<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Console\ConsoleDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SAS-1 · Tablero y auditoría propia de la consola.
 */
final class ConsoleController
{
    public function __construct(private readonly ConsoleDashboard $dashboard) {}

    public function metrics(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->metrics()]);
    }

    public function auditEvents(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'action' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $result = $this->dashboard->auditEvents(
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '25'),
            $request->query('action') === null ? null : (string) $request->query('action'),
        );

        return response()->json($result);
    }
}
