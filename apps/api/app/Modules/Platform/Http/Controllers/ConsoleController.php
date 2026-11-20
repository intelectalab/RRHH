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
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'platform_user_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);
        $q = fn (string $k): ?string => $request->query($k) === null || $request->query($k) === '' ? null : (string) $request->query($k);

        $result = $this->dashboard->auditEvents(
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '25'),
            $q('action'), $q('tenant_id'), $q('platform_user_id'), $q('from'), $q('to'),
        );

        return response()->json($result);
    }
}
