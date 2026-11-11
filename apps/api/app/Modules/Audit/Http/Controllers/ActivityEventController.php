<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Application\AuditStorePresenter;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AUD-3 · Actividad de usuarios (ingresos, vistas, descargas, búsquedas). Lee solo activity_events:
 * nunca mezcla la auditoría de negocio ni los logs técnicos. Con filter[subject_type] y
 * filter[subject_id] es la línea de actividad de una ficha.
 * Filtros: filter[user_id], filter[activity], filter[subject_type], filter[subject_id], filter[from], filter[to].
 */
final class ActivityEventController
{
    private const ALLOWED = ['user_id', 'activity', 'subject_type', 'subject_id', 'from', 'to'];

    public function __construct(private readonly AuditStorePresenter $presenter) {}

    public function __invoke(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '50')));
        $query = DB::table('activity_events as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select(['a.id', 'a.user_id', 'a.activity', 'a.subject_type', 'a.subject_id', 'a.metadata', 'a.occurred_at', 'u.display_name as user_name']);
        $this->applyFilters($query, $request);

        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (! is_array($decoded) || ! isset($decoded['t'], $decoded['id'])) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'));
            }
            $query->where(fn (Builder $q) => $q->where('a.occurred_at', '<', $decoded['t'])
                ->orWhere(fn (Builder $w) => $w->where('a.occurred_at', '=', $decoded['t'])->where('a.id', '<', $decoded['id'])));
        }

        $rows = $query->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $last = $rows->last();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->presenter->activity($r))->values()->all(),
            'meta' => [
                'per_page' => $perPage,
                'next_cursor' => $more && $last !== null ? base64_encode((string) json_encode(['t' => $last->occurred_at, 'id' => $last->id])) : null,
            ],
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $filter = $request->query('filter', []);
        if (! is_array($filter) || array_diff(array_keys($filter), self::ALLOWED) !== []) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::ALLOWED);
        }
        $invalid = fn () => new InvalidFilterException((string) trans('errors.invalid_filter'), self::ALLOWED);
        foreach (['user_id', 'subject_id'] as $key) {
            if (isset($filter[$key])) {
                if (! is_string($filter[$key]) || preg_match('/^[0-9a-f-]{36}$/i', $filter[$key]) !== 1) {
                    throw $invalid();
                }
                $query->where('a.'.$key, $filter[$key]);
            }
        }
        foreach (['activity', 'subject_type'] as $key) {
            if (isset($filter[$key]) && is_string($filter[$key]) && $filter[$key] !== '') {
                $query->where('a.'.$key, $filter[$key]);
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            if (! isset($filter[$key])) {
                continue;
            }
            $value = $filter[$key];
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || strtotime($value) === false) {
                throw $invalid();
            }
            $query->where('a.occurred_at', $op, $key === 'to' ? date('Y-m-d', (int) strtotime($value.' +1 day')) : $value);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn (Builder $w) => $w->where('a.activity', 'ilike', $like)->orWhere('u.display_name', 'ilike', $like));
        }
    }
}
