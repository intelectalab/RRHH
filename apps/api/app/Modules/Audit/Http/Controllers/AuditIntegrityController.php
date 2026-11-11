<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Application\AuditStorePresenter;
use App\Modules\Audit\Application\RetentionPolicies;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AUD-5 · Resultado de las verificaciones programadas de la cadena de auditoría (la más reciente primero)
 * y AUD-3 · políticas de conservación de los tres registros. Solo lectura.
 */
final class AuditIntegrityController
{
    public function __construct(private readonly AuditStorePresenter $presenter) {}

    public function checks(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '20')));
        $filter = $request->query('filter', []);
        if (! is_array($filter) || array_diff(array_keys($filter), ['status']) !== []
            || (isset($filter['status']) && ! in_array($filter['status'], ['ok', 'failed'], true))) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['status']);
        }
        $query = DB::table('audit_integrity_checks');
        if (isset($filter['status'])) {
            $query->where('status', $filter['status']);
        }
        $rows = $query->orderByDesc('started_at')->orderByDesc('id')->limit($perPage)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->presenter->check($r))->values()->all(),
            'meta' => ['per_page' => $perPage, 'next_cursor' => null],
        ]);
    }

    public function retention(RetentionPolicies $policies): JsonResponse
    {
        $last = DB::table('audit_integrity_checks')->orderByDesc('started_at')->first();

        return response()->json([
            'data' => $policies->describe(),
            'meta' => ['last_check' => $last === null ? null : $this->presenter->check($last)],
        ]);
    }
}
