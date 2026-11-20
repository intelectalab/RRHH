<?php

namespace App\Modules\Payroll\Infrastructure\Workflow;

use App\Modules\Payroll\Application\PayrollPeriods;
use App\Shared\Workflow\ApprovalOutcomeHandler;
use Illuminate\Support\Facades\DB;

/**
 * PER-8 · WFL-2 · El motor de aprobaciones aplica aquí la decisión sobre un periodo enviado: aprobado, el periodo se
 * aprueba a nombre de quien decidió (con la segregación de funciones, RN-PER-01); devuelto o cancelado, vuelve a
 * «calculado» con el motivo. Idempotente: un periodo que ya no está en aprobación no cambia.
 */
final class PayrollPeriodOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly PayrollPeriods $periods) {}

    public function subjectType(): string
    {
        return PayrollPeriods::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $decider = DB::table('workflow_decisions AS d')->join('workflow_tasks AS t', 't.id', '=', 'd.workflow_task_id')
            ->where('t.workflow_instance_id', $approvalRequestId)->orderByDesc('d.decided_at')->value('d.decided_by_user_id');
        $this->periods->approvedByFlow($subjectId, $decider === null ? null : (string) $decider);
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->periods->rejectedByFlow($subjectId, $reason);
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->periods->rejectedByFlow($subjectId, $reason);
    }
}
