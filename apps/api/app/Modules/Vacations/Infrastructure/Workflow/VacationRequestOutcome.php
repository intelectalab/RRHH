<?php

namespace App\Modules\Vacations\Infrastructure\Workflow;

use App\Modules\Vacations\Application\VacationRequestService;
use App\Shared\Workflow\ApprovalOutcomeHandler;
use Illuminate\Support\Facades\DB;

/**
 * VAC-3 · WFL-4 · El motor de aprobaciones aplica aquí la decisión final sobre una solicitud de vacaciones,
 * en la misma transacción: aprobada reserva los días en el libro (RN-VAC-03) y, si el saldo ya no alcanza,
 * la decisión no se registra; rechazada guarda el motivo; cancelada se anula.
 */
final class VacationRequestOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly VacationRequestService $requests) {}

    public function subjectType(): string
    {
        return VacationRequestService::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $this->requests->approvedByFlow($subjectId, $this->decidedBy($approvalRequestId));
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->requests->rejectedByFlow($subjectId, $reason, $this->decidedBy($approvalRequestId));
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->requests->cancelledByFlow($subjectId, $reason);
    }

    /** Quien decidió el último paso (o el usuario de la petición, si el motor no lo registra). */
    private function decidedBy(string $approvalRequestId): ?string
    {
        $user = request()->user('ayni');
        if ($user !== null) {
            return (string) $user->getAuthIdentifier();
        }
        $last = DB::table('workflow_decisions as d')->join('workflow_tasks as t', 't.id', '=', 'd.workflow_task_id')
            ->where('t.workflow_instance_id', $approvalRequestId)->whereIn('d.decision', ['approve', 'reject'])
            ->orderByDesc('d.decided_at')->value('d.decided_by_user_id');

        return $last === null ? null : (string) $last;
    }
}
