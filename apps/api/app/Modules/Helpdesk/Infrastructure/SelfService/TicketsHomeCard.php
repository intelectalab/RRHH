<?php

namespace App\Modules\Helpdesk\Infrastructure\SelfService;

use App\Shared\SelfService\HomeCard;
use Illuminate\Support\Facades\DB;

/** ESS-5 · Tarjeta del inicio: solicitudes a RRHH abiertas, las que esperan su respuesta y las últimas. */
final class TicketsHomeCard implements HomeCard
{
    public function key(): string
    {
        return 'hr_requests';
    }

    public function permission(): string
    {
        return 'helpdesk.tickets.create';
    }

    public function order(): int
    {
        return 50;
    }

    public function card(string $userId, string $employmentId): array
    {
        $base = DB::table('tickets')->where('requester_user_id', $userId)->whereNull('deleted_at');
        $open = (clone $base)->whereIn('status', ['new', 'in_progress', 'waiting_info', 'resolved'])->count();
        $waiting = (clone $base)->where('status', 'waiting_info')->count();
        $latest = (clone $base)->orderByDesc('updated_at')->limit(3)->get(['id', 'number', 'subject', 'status']);

        return [
            'href' => '/mi/solicitudes',
            'pending' => $waiting,
            'data' => [
                'open' => $open,
                'waiting_info' => $waiting,
                'latest' => $latest->map(fn ($t) => ['id' => (string) $t->id, 'number' => (string) $t->number, 'subject' => (string) $t->subject, 'status' => (string) $t->status])->values()->all(),
            ],
        ];
    }
}
