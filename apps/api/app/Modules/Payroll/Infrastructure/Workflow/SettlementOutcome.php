<?php

namespace App\Modules\Payroll\Infrastructure\Workflow;

use App\Modules\Payroll\Application\Settlements\Settlements;
use App\Shared\Workflow\ApprovalOutcomeHandler;
use Illuminate\Support\Facades\DB;

/**
 * LIQ-3 · WFL-2 · Decisión del flujo sobre una liquidación enviada: aprobada, la aprueba quien decidió (con la
 * segregación de funciones, RN-LIQ-06); devuelta o cancelada, vuelve a «calculada» con el motivo. Idempotente.
 */
final class SettlementOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly Settlements $settlements) {}

    public function subjectType(): string
    {
        return Settlements::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $decider = DB::table('workflow_decisions AS d')->join('workflow_tasks AS t', 't.id', '=', 'd.workflow_task_id')
            ->where('t.workflow_instance_id', $approvalRequestId)->orderByDesc('d.decided_at')->value('d.decided_by_user_id');
        $this->settlements->approvedByFlow($subjectId, $decider === null ? null : (string) $decider);
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->settlements->rejectedByFlow($subjectId, $reason);
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->settlements->rejectedByFlow($subjectId, $reason);
    }
}
