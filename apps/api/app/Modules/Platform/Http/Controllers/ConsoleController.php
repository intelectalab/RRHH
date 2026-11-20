<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Console\BusinessMetrics;
use App\Modules\Platform\Application\Console\ConsoleDashboard;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SAS-1 · Tablero y auditoría propia de la consola.
 */
final class ConsoleController
{
    public function __construct(
        private readonly ConsoleDashboard $dashboard,
        private readonly BusinessMetrics $metrics,
    ) {}

    /** SAS-10 · Tablero del negocio SaaS por periodo y plan. */
    public function metrics(Request $request): JsonResponse
    {
        $date = fn (string $key): ?string => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            && strtotime((string) $request->query($key)) !== false ? (string) $request->query($key) : null;
        foreach (['from', 'to'] as $key) {
            if ($request->query($key) !== null && $date($key) === null) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), [$key]);
            }
        }
        $to = $date('to') ?? now()->toDateString();
        $from = $date('from') ?? now()->subDays(30)->toDateString();
        $plan = $request->query('plan_id');
        if ($plan !== null && (! is_string($plan) || preg_match('/^[0-9a-f-]{36}$/i', $plan) !== 1 || ! DB::table('plans')->where('id', $plan)->exists())) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['plan_id']);
        }
        if ($from > $to) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['from', 'to']);
        }

        return response()->json(['data' => $this->metrics->compute($from, $to, is_string($plan) ? $plan : null)]);
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
