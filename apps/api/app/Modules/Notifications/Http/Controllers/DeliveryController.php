<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * NOT-1 · Registro de entregas por canal externo: estado y error. La dirección se muestra enmascarada.
 */
final class DeliveryController
{
    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = NotificationDelivery::query();
        $status = $request->input('filter.status', $request->query('status'));
        if (is_string($status) && in_array($status, ['queued', 'sent', 'delivered', 'failed', 'bounced', 'suppressed'], true)) {
            $query->where('status', $status);
        }
        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $rows = NotificationDelivery::query()->whereIn('id', $ids)->get()->keyBy('id')->only($ids)->values();
        $notes = DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.recipient_user_id')
            ->whereIn('n.id', $rows->pluck('notification_id')->filter()->values())->get(['n.id', 'n.category', 'n.title', 'u.display_name'])->keyBy('id');

        return response()->json([
            'data' => $rows->map(function (NotificationDelivery $d) use ($notes): array {
                $n = $d->notification_id !== null ? $notes->get($d->notification_id) : null;

                return [
                    'id' => $d->id,
                    'channel' => $d->channel,
                    'recipient' => $this->mask($d->recipient_address_enc),
                    'recipient_name' => $n->display_name ?? null,
                    'category' => $n->category ?? null,
                    'title' => $n->title ?? null,
                    'status' => $d->status,
                    'provider' => $d->provider,
                    'attempts' => $d->attempts,
                    'error' => $d->error,
                    'sent_at' => $d->sent_at?->toIso8601String(),
                    'created_at' => $d->created_at?->toIso8601String(),
                ];
            })->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    private function mask(?string $enc): ?string
    {
        if ($enc === null) {
            return null;
        }
        $address = Crypt::decryptString($enc);
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).($domain !== '' ? '@'.$domain : '');
    }
}
