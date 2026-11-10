<?php

namespace App\Modules\Audit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registro de accesos a datos sensibles (regla 7): quién leyó qué campos, de qué registro y cuándo.
 */
final class SensitiveAccessLogController
{
    public function __invoke(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '50')));
        $filter = (array) $request->query('filter', []);

        $query = DB::table('data_access_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->select(['l.*', 'u.display_name as actor_name']);
        if (is_string($filter['actor_id'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $filter['actor_id']) === 1) {
            $query->where('l.user_id', $filter['actor_id']);
        }
        if (is_string($filter['category'] ?? null) && $filter['category'] !== '') {
            $query->where('l.data_category', $filter['category']);
        }

        $rows = $query->orderByDesc('l.occurred_at')->limit($perPage)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'actor_id' => (string) ($r->user_id ?? ''),
                'actor_name' => $r->actor_name,
                'entity' => (string) ($r->entity_type ?? ''),
                'entity_id' => (string) ($r->entity_id ?? ''),
                'fields' => array_values(array_filter(explode(',', trim((string) ($r->fields ?? '{}'), '{}')))),
                'category' => $r->data_category,
                'channel' => $r->access_kind,
                'purpose' => $r->purpose,
                'occurred_at' => (new \DateTimeImmutable((string) $r->occurred_at))->format(DATE_ATOM),
            ])->all(),
            'meta' => ['per_page' => $perPage, 'next_cursor' => null],
        ]);
    }
}
