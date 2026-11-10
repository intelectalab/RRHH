<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOT-2 · Centro de notificaciones del usuario actual.
 */
final class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $params = PaginationParams::fromRequest($request);
        $query = Notification::query()->where('recipient_user_id', $userId)->whereNull('archived_at');
        if (in_array($request->input('filter.unread', $request->query('unread')), ['1', 'true'], true)) {
            $query->whereNull('read_at');
        }
        $category = $request->input('filter.category');
        if (is_string($category) && $category !== '') {
            $query->where('category', 'like', addcslashes($category, '%_\\').'%');
        }
        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $rows = Notification::query()->whereIn('id', $ids)->get()->keyBy('id');
        // Conserva el orden de la página (only() respetaría el orden de la consulta, no el de los ids).
        $rows = collect($ids)->map(fn ($id) => $rows->get($id))->filter()->values();

        return response()->json([
            'data' => $rows->map(fn (Notification $n) => [
                'id' => $n->id,
                'category' => $n->category,
                'group' => explode('.', $n->category)[0],
                'title' => $n->title,
                'body' => $n->body,
                'action_url' => $n->action_url,
                'priority' => $n->priority,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'per_page' => $page['meta']['per_page'],
                'next_cursor' => $page['meta']['next_cursor'],
                'unread' => Notification::query()->where('recipient_user_id', $userId)->whereNull('archived_at')->whereNull('read_at')->count(),
            ],
        ]);
    }

    /**
     * Marcar como leídas: ids concretos o todas.
     */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:200'], 'ids.*' => ['uuid'], 'all' => ['sometimes', 'boolean']]);
        $userId = (string) $request->user()?->getAuthIdentifier();
        $query = Notification::query()->where('recipient_user_id', $userId)->whereNull('read_at');
        if (! ($data['all'] ?? false)) {
            $query->whereIn('id', $data['ids'] ?? []);
        }
        $updated = $query->update(['read_at' => now()]);

        return response()->json(['data' => [
            'updated' => $updated,
            'unread' => Notification::query()->where('recipient_user_id', $userId)->whereNull('archived_at')->whereNull('read_at')->count(),
        ]]);
    }
}
