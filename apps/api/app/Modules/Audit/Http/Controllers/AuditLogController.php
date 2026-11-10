<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Application\AuditPresenter;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AUD-4 · Visor de auditoría (solo lectura: no existe ruta que modifique la auditoría).
 * Filtros: filter[actor_id], filter[entity], filter[entity_id], filter[action],
 * filter[from], filter[to] (fechas) y q (acción o entidad).
 */
final class AuditLogController
{
    public function __construct(private readonly AuditPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '50')));
        $query = $this->base();
        $this->applyFilters($query, $request);

        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (is_array($decoded) && isset($decoded['t'], $decoded['id'])) {
                $query->where(fn (Builder $q) => $q->where('a.occurred_at', '<', $decoded['t'])
                    ->orWhere(fn (Builder $w) => $w->where('a.occurred_at', '=', $decoded['t'])->where('a.id', '<', $decoded['id'])));
            }
        }

        $rows = $query->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $last = $rows->last();
        $permissions = $this->permissions($request);

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->presenter->present($r, $permissions))->values()->all(),
            'meta' => [
                'per_page' => $perPage,
                'next_cursor' => $more && $last !== null ? base64_encode((string) json_encode(['t' => $last->occurred_at, 'id' => $last->id])) : null,
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $row = $this->base()->where('a.id', $id)->first();
        abort_if($row === null, 404);

        return response()->json(['data' => $this->presenter->present($row, $this->permissions($request))]);
    }

    private function base(): Builder
    {
        return DB::table('audit_events as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->select(['a.*', 'u.display_name as actor_name']);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $filter = (array) $request->query('filter', []);
        $uuid = fn ($v) => is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v) === 1;

        if ($uuid($filter['actor_id'] ?? null)) {
            $query->where('a.actor_user_id', $filter['actor_id']);
        }
        if (is_string($filter['entity'] ?? null) && $filter['entity'] !== '') {
            $query->where('a.entity_type', $filter['entity']);
        }
        if ($uuid($filter['entity_id'] ?? null)) {
            $query->where('a.entity_id', $filter['entity_id']);
        }
        if (is_string($filter['action'] ?? null) && $filter['action'] !== '') {
            $query->where('a.action', $filter['action']);
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $value = $filter[$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $date = $key === 'to' ? date('Y-m-d', (int) strtotime($value.' +1 day')) : $value;
                $query->where('a.occurred_at', $op, $date);
            }
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn (Builder $w) => $w->where('a.action', 'ilike', $like)->orWhere('a.entity_type', 'ilike', $like)->orWhere('u.display_name', 'ilike', $like));
        }
    }

    private function permissions(Request $request): EffectivePermissions
    {
        /** @var EffectivePermissions */
        return $request->attributes->get('effective_permissions');
    }
}
