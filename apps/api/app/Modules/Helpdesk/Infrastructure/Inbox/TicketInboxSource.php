<?php

namespace App\Modules\Helpdesk\Infrastructure\Inbox;

use App\Shared\Inbox\InboxSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ESS-5 · «RRHH recibe la solicitud en su bandeja»: las nuevas y en atención asignadas al usuario, y las sin
 * asignar de un tipo que atiende uno de sus roles.
 */
final class TicketInboxSource implements InboxSource
{
    public function kind(): string
    {
        return 'ticket';
    }

    public function permission(): string
    {
        return 'helpdesk.tickets.update';
    }

    public function items(string $userId, int $limit): array
    {
        return $this->query($userId)->orderByRaw('t.resolution_due_at asc nulls last')->orderBy('t.created_at')->limit($limit)
            ->get(['t.id', 't.number', 't.subject', 't.priority', 't.resolution_due_at', 't.created_at', 'c.name as category_name'])
            ->map(fn ($t) => [
                'id' => (string) $t->id,
                'type' => (string) $t->priority,
                'title' => $t->number.' · '.$t->subject,
                'subtitle' => (string) $t->category_name,
                'due_at' => $t->resolution_due_at === null ? null : (string) $t->resolution_due_at,
                'overdue' => $t->resolution_due_at !== null && strtotime((string) $t->resolution_due_at) < time(),
                'created_at' => (string) $t->created_at,
                'href' => '/personas/solicitudes?id='.$t->id,
            ])->values()->all();
    }

    public function count(string $userId): int
    {
        return $this->query($userId)->count();
    }

    private function query(string $userId): Builder
    {
        $today = now()->toDateString();
        $roles = DB::table('user_roles')->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))->pluck('role_id')->all();

        return DB::table('tickets as t')->join('ticket_categories as c', 'c.id', '=', 't.ticket_category_id')
            ->whereNull('t.deleted_at')->whereIn('t.status', ['new', 'in_progress'])->where('t.requester_user_id', '<>', $userId)
            ->where(fn ($w) => $w->where('t.assignee_user_id', $userId)
                ->orWhere(fn ($u) => $u->whereNull('t.assignee_user_id')->whereIn('c.default_assignee_role_id', $roles === [] ? ['00000000-0000-0000-0000-000000000000'] : $roles)));
    }
}
