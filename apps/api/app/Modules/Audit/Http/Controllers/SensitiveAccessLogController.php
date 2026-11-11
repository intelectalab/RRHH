<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registro de accesos a datos sensibles (regla 7): quién leyó qué campos, de qué registro y cuándo.
 */
final class SensitiveAccessLogController
{
    /** Categorías del registro (enum data_category). */
    private const CATEGORIES = ['salary', 'health', 'complaint', 'disciplinary', 'biometric', 'bank_account', 'identity_document', 'union', 'other'];

    /** Canales del registro (enum access_kind). */
    private const CHANNELS = ['view', 'export', 'download', 'print', 'api', 'ai'];

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
            if (! in_array($filter['category'], self::CATEGORIES, true)) {
                throw new InvalidFilterException('Categoría de dato no válida.', self::CATEGORIES);
            }
            $query->where('l.data_category', $filter['category']);
        }
        // SEG-12: las exportaciones y descargas se consultan por canal.
        if (is_string($filter['channel'] ?? null) && $filter['channel'] !== '') {
            if (! in_array($filter['channel'], self::CHANNELS, true)) {
                throw new InvalidFilterException('Canal no válido.', self::CHANNELS);
            }
            $query->where('l.access_kind', $filter['channel']);
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
