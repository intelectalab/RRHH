<?php

namespace App\Modules\Helpdesk\Infrastructure\Help;

use App\Modules\Helpdesk\Application\TicketCategoryService;
use App\Modules\Helpdesk\Application\TicketService;
use App\Modules\Helpdesk\Infrastructure\Models\TicketCategory;
use App\Shared\Help\HrRequests;
use Illuminate\Support\Facades\DB;

/**
 * AYU-6 · La consulta derivada desde la ayuda entra como solicitud a RRHH del tipo «general», por el canal
 * «help_center», y RRHH la atiende en su bandeja como cualquier otra (avisos, estados y mensajes de ESS-5).
 */
final class HelpEscalationRequests implements HrRequests
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketCategoryService $categories,
    ) {}

    public function open(string $escalationId, string $userId, ?string $employmentId, string $subject, string $description): array
    {
        $this->categories->ensureDefaults();
        $category = TicketCategory::query()->where('code', 'general')->where('status', 'active')->first()
            ?? TicketCategory::query()->where('status', 'active')->orderBy('sort_order')->firstOrFail();
        $ticket = $this->tickets->create(['category_id' => (string) $category->id, 'subject' => $subject, 'description' => $description], $userId, $employmentId, null);
        DB::table('tickets')->where('id', $ticket->id)->update(['channel' => 'help_center', 'help_escalation_id' => $escalationId]);

        return ['id' => (string) $ticket->id, 'number' => (string) $ticket->number];
    }

    public function forEscalations(array $escalationIds): array
    {
        if ($escalationIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('tickets')->whereIn('help_escalation_id', $escalationIds)->whereNull('deleted_at')->get(['id', 'number', 'status', 'help_escalation_id']) as $t) {
            $out[(string) $t->help_escalation_id] = ['id' => (string) $t->id, 'number' => (string) $t->number, 'status' => (string) $t->status];
        }

        return $out;
    }
}
