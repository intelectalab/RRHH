<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Domain\PersonnelActionStateMachine;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\PersonnelAction;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Vigencia\EffectiveDated;
use App\Shared\Workflow\ApprovalRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HIS-2 · HIS-4 · Movimientos de personal: la única vía para cambiar los datos laborales de la asignación.
 *
 * Registrar un movimiento (tipo, fecha efectiva, cambios, motivo):
 * - Si lo propone un jefe (sin people.assignments.create) o su tipo está en movimientos.tipos_con_aprobacion,
 *   queda «en aprobación» con el flujo publicado para «personnel_action» (WFL-2); la decisión vuelve por
 *   PersonnelActionOutcome en la misma transacción.
 * - Si su fecha es futura, queda «programado» y no afecta nada hasta ese día (RN-HIS-04): lo aplica
 *   personnel-actions:apply-due. Antes de su fecha se edita o se anula.
 * - Con fecha de hoy o pasada se aplica en el acto (AssignmentMovements). Con fecha pasada es retroactivo:
 *   emite employment.retroactive_change con el rango afectado; si cae en un periodo de planilla cerrado
 *   exige people.assignments.correct y el periodo cerrado no se toca (RN-HIS-05).
 *
 * Corregir un movimiento aplicado (RN-HIS-06): crea un movimiento de corrección, el original queda
 * «reemplazado» y enlazado, con su valor original en la historia.
 *
 * Punto de extensión para HIS-3: un ascenso con aumento registra su compensación en el servicio de
 * compensación con el personnel_action_id de este movimiento (employment_compensations.personnel_action_id);
 * aquí no se toca el sueldo (RN-HIS-08 enlaza el incremento con su movimiento y su aprobación).
 */
final class PersonnelActions
{
    public const SUBJECT_TYPE = 'personnel_action';

    /** Estados que bloquean otro movimiento de la misma relación hasta resolverse. */
    public const OPEN_STATES = ['pending_approval', 'approved', 'scheduled'];

    public function __construct(
        private readonly AssignmentMovements $movements,
        private readonly PersonnelActionStateMachine $machine,
        private readonly ApprovalRequests $approvals,
        private readonly ParameterResolver $parameters,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{kind?: string|null, effective_on: string, reason?: string|null, changes: array<string, mixed>}  $input
     * @param  bool  $canCreate  tiene people.assignments.create (si no, solo propone y siempre pasa por aprobación)
     * @param  bool  $canCorrect  tiene people.assignments.correct (fechas en periodos de planilla cerrados)
     */
    public function register(Employment $employment, array $input, ?string $userId, bool $canCreate, bool $canCorrect): PersonnelAction
    {
        $from = EffectiveDated::day($input['effective_on']);
        $changes = array_intersect_key($input['changes'], array_flip(AssignmentMovements::FIELDS));
        $kind = $input['kind'] ?? null;
        $reason = ($input['reason'] ?? '') !== '' ? $input['reason'] : null;

        return DB::transaction(function () use ($employment, $from, $changes, $kind, $reason, $userId, $canCreate, $canCorrect): PersonnelAction {
            Employment::query()->whereKey($employment->id)->lockForUpdate()->first();
            $this->assertNoOpenAction($employment);
            $preview = $this->movements->preview($employment, $changes, $from, $kind);
            $retro = $this->retroactivity($employment, $from, $canCorrect);
            $needsApproval = ! $canCreate || in_array($preview['kind'], $this->approvalKinds($employment), true);
            if ($needsApproval && $userId === null) {
                throw new AyniException('forbidden', 403, (string) trans('people.movements.requester_required'));
            }

            $action = new PersonnelAction([
                'employment_id' => $employment->id, 'kind' => $preview['kind'], 'effective_on' => $from, 'reason' => $reason,
                'status' => 'draft', 'requested_by_user_id' => $userId, 'changes' => $changes,
                'payload' => ['before' => $preview['before'], 'after' => $preview['after']],
            ] + $retro);
            $action->save();
            $this->audit->record('personnel_action.created', 'personnel_action', $action->id, null, $this->auditable($action), $reason);

            if ($needsApproval) {
                $approvalId = $this->approvals->start(self::SUBJECT_TYPE, $action->id, (string) $userId, [
                    'kind' => $action->kind, 'legal_entity_id' => $employment->legal_entity_id, 'employment_id' => $employment->id,
                    'org_unit_id' => $preview['values']['org_unit_id'] ?? null, 'effective_on' => $from, 'is_retroactive' => $action->is_retroactive,
                ]);
                $this->transition($action, 'pending_approval', ['workflow_instance_id' => $approvalId]);
            } elseif ($from > $this->today()) {
                $this->transition($action, $this->machine->getNextState('draft', PersonnelActionStateMachine::SCHEDULE));
            } else {
                $this->apply($action, $employment, $userId);
            }
            $this->outbox->record(DomainEventTypes::PERSONNEL_ACTION_REQUESTED, 'personnel_action', $action->id, [
                'personnel_action_id' => $action->id, 'employment_id' => $employment->id, 'kind' => $action->kind,
                'effective_on' => $from, 'status' => $action->status,
            ]);

            return $action->refresh();
        });
    }

    /**
     * HIS-4 · Edita un movimiento programado antes de su fecha (fecha, cambios, motivo). La fecha nueva
     * también debe ser futura; para aplicar hoy se anula y se registra de nuevo.
     *
     * @param  array{effective_on?: string, reason?: string|null, changes?: array<string, mixed>}  $input
     */
    public function update(PersonnelAction $action, array $input, ?string $userId): PersonnelAction
    {
        return DB::transaction(function () use ($action, $input): PersonnelAction {
            /** @var PersonnelAction $locked */
            $locked = PersonnelAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            $this->assertCan($locked, PersonnelActionStateMachine::EDIT);
            $employment = $this->employment($locked);
            $from = EffectiveDated::day($input['effective_on'] ?? $locked->effective_on->toDateString());
            if ($from <= $this->today()) {
                throw ValidationException::withMessages(['effective_on' => [(string) trans('people.movements.edit_future_only')]]);
            }
            $changes = isset($input['changes']) ? array_intersect_key($input['changes'], array_flip(AssignmentMovements::FIELDS)) : (array) $locked->changes;
            $preview = $this->movements->preview($employment, $changes, $from, $locked->kind === 'data_correction' ? null : $locked->kind);
            $before = $this->auditable($locked);
            $locked->forceFill([
                'effective_on' => $from, 'changes' => $changes, 'payload' => ['before' => $preview['before'], 'after' => $preview['after']],
                'reason' => array_key_exists('reason', $input) ? $input['reason'] : $locked->reason,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->audit->record('personnel_action.updated', 'personnel_action', $locked->id, $before, $this->auditable($locked));

            return $locked->refresh();
        });
    }

    /** HIS-4 · Anula un movimiento programado o en aprobación antes de aplicarse (anula los pasos pendientes). */
    public function cancel(PersonnelAction $action, string $reason, ?string $userId): PersonnelAction
    {
        return DB::transaction(function () use ($action, $reason, $userId): PersonnelAction {
            /** @var PersonnelAction $locked */
            $locked = PersonnelAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            $this->assertCan($locked, PersonnelActionStateMachine::CANCEL);
            $approval = $locked->status === 'pending_approval' ? $locked->workflow_instance_id : null;
            $this->markCancelled($locked, $reason, $userId);
            if ($approval !== null) {
                // El motor llama a cancelled() del handler, que ya no hace nada (idempotente).
                $this->approvals->cancel($approval, $reason);
            }

            return $locked->refresh();
        });
    }

    /**
     * HIS-4 · RN-HIS-06 · Corrige un movimiento aplicado con los valores correctos y el motivo. Solo el último
     * movimiento de la relación (sin otros posteriores ni programados).
     *
     * @param  array<string, mixed>  $changes
     */
    public function correct(PersonnelAction $action, array $changes, string $reason, ?string $userId): PersonnelAction
    {
        return DB::transaction(function () use ($action, $changes, $reason, $userId): PersonnelAction {
            /** @var PersonnelAction $locked */
            $locked = PersonnelAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            $this->assertCan($locked, PersonnelActionStateMachine::CORRECT);
            if (! in_array($locked->kind, AssignmentMovements::KINDS, true)) {
                throw new AyniException('invalid_transition', 409, (string) trans('people.movements.not_correctable'));
            }
            $employment = $this->employment($locked);
            Employment::query()->whereKey($employment->id)->lockForUpdate()->first();
            $this->assertNoOpenAction($employment);
            $changes = array_intersect_key($changes, array_flip(AssignmentMovements::FIELDS));
            $from = $locked->effective_on->toDateString();

            $correction = new PersonnelAction([
                'employment_id' => $employment->id, 'kind' => 'data_correction', 'effective_on' => $from, 'reason' => $reason,
                'status' => 'applied', 'requested_by_user_id' => $userId, 'changes' => $changes, 'applied_at' => now(),
                'replaces_personnel_action_id' => $locked->id,
            ] + $this->retroactivity($employment, $from, true));
            $correction->save();
            $result = $this->movements->correctRow($employment, $locked->id, $changes, $correction->id, $reason, $userId);
            $correction->forceFill(['payload' => ['before' => $result['before'], 'after' => $result['after']]])->save();

            $before = $this->auditable($locked);
            $locked->forceFill([
                'status' => $this->machine->getNextState($locked->status, PersonnelActionStateMachine::CORRECT),
                'replaced_by_personnel_action_id' => $correction->id, 'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->audit->record('personnel_action.corrected', 'personnel_action', $locked->id, $before, $this->auditable($locked) + ['correction_id' => $correction->id], $reason);
            $this->emitRetroactive($correction, $employment);

            return $correction->refresh();
        });
    }

    /**
     * HIS-4 · Aplica los movimientos programados cuya fecha llegó (personnel-actions:apply-due). Un movimiento
     * que ya no se puede aplicar (la estructura cambió) queda programado y se informa.
     *
     * @return array{applied: int, blocked: list<string>}
     */
    public function applyDue(): array
    {
        $applied = 0;
        $blocked = [];
        $due = PersonnelAction::query()->where('status', 'scheduled')->where('effective_on', '<=', $this->today())->orderBy('effective_on')->get();
        foreach ($due as $action) {
            try {
                DB::transaction(function () use ($action): void {
                    /** @var PersonnelAction $locked */
                    $locked = PersonnelAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status !== 'scheduled') {
                        return;
                    }
                    $this->apply($locked, $this->employment($locked), null);
                });
                $applied++;
            } catch (\Throwable $e) {
                report($e);
                $blocked[] = $action->id;
            }
        }

        return ['applied' => $applied, 'blocked' => $blocked];
    }

    /** WFL-4 · Aprobado por el flujo: programado si su fecha es futura; si no, se aplica ya (o la decisión no queda). */
    public function approvedByFlow(string $id): void
    {
        /** @var PersonnelAction|null $action */
        $action = PersonnelAction::query()->whereKey($id)->lockForUpdate()->first();
        if ($action === null || $action->status !== 'pending_approval') {
            return;
        }
        $this->transition($action, $this->machine->getNextState($action->status, PersonnelActionStateMachine::APPROVE), ['decided_at' => now()]);
        if ($action->effective_on->toDateString() > $this->today()) {
            $this->transition($action, $this->machine->getNextState('approved', PersonnelActionStateMachine::SCHEDULE));

            return;
        }
        $this->apply($action, $this->employment($action), null);
    }

    public function rejectedByFlow(string $id, ?string $reason): void
    {
        /** @var PersonnelAction|null $action */
        $action = PersonnelAction::query()->whereKey($id)->lockForUpdate()->first();
        if ($action === null || $action->status !== 'pending_approval') {
            return;
        }
        $this->transition($action, $this->machine->getNextState($action->status, PersonnelActionStateMachine::REJECT), ['decided_at' => now(), 'decision_reason' => $reason]);
    }

    public function cancelledByFlow(string $id, ?string $reason): void
    {
        /** @var PersonnelAction|null $action */
        $action = PersonnelAction::query()->whereKey($id)->lockForUpdate()->first();
        if ($action === null || ! $this->machine->can($action->status, PersonnelActionStateMachine::CANCEL)) {
            return;
        }
        $this->markCancelled($action, $reason ?? '', null);
    }

    /**
     * Movimientos programados o en aprobación posteriores a una fecha (para el cese: no se aplican solos).
     *
     * @return list<string>
     */
    public function openAfter(string $employmentId, string $date): array
    {
        return array_values(PersonnelAction::query()->where('employment_id', $employmentId)->whereIn('status', self::OPEN_STATES)
            ->where('effective_on', '>', $date)->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    /**
     * Presentación pública del movimiento con los nombres de lo que cambia.
     *
     * @return array<string, mixed>
     */
    public function present(PersonnelAction $action, bool $withApproval = false): array
    {
        $payload = $action->payload ?? [];

        return [
            'id' => $action->id,
            'employment_id' => $action->employment_id,
            'kind' => $action->kind,
            'effective_on' => $action->effective_on->toDateString(),
            'reason' => $action->reason,
            'status' => $action->status,
            'changes' => $action->changes ?? [],
            'before' => $this->labelled(is_array($payload['before'] ?? null) ? $payload['before'] : []),
            'after' => $this->labelled(is_array($payload['after'] ?? null) ? $payload['after'] : []),
            'requested_by_user_id' => $action->requested_by_user_id,
            'approval_request_id' => $action->workflow_instance_id,
            'approval' => $withApproval && $action->workflow_instance_id !== null ? $this->approvals->summary($action->workflow_instance_id) : null,
            'applied_at' => $action->applied_at?->toIso8601String(),
            'decided_at' => $action->decided_at?->toIso8601String(),
            'decision_reason' => $action->decision_reason,
            'cancelled_at' => $action->cancelled_at?->toIso8601String(),
            'replaces_personnel_action_id' => $action->replaces_personnel_action_id,
            'replaced_by_personnel_action_id' => $action->replaced_by_personnel_action_id,
            'is_retroactive' => $action->is_retroactive,
            'affected_from' => $action->affected_from?->toDateString(),
            'affected_to' => $action->affected_to?->toDateString(),
            'allowed_actions' => array_values(array_intersect(
                $this->machine->allowedActions($action->status),
                [PersonnelActionStateMachine::EDIT, PersonnelActionStateMachine::CANCEL, PersonnelActionStateMachine::CORRECT],
            )),
            'created_at' => $action->created_at->toIso8601String(),
            'updated_at' => $action->updated_at->toIso8601String(),
        ];
    }

    private function apply(PersonnelAction $action, Employment $employment, ?string $userId): void
    {
        $this->assertCan($action, PersonnelActionStateMachine::APPLY);
        $this->movements->applyChanges($employment, (array) $action->changes, $action->effective_on->toDateString(),
            $action->kind === 'data_correction' ? 'data_correction' : $action->kind, $action->reason, $userId ?? $action->requested_by_user_id, $action->id);
        $action->refresh();
        $this->emitRetroactive($action, $employment);
    }

    private function emitRetroactive(PersonnelAction $action, Employment $employment): void
    {
        if (! $action->is_retroactive || $action->affected_from === null || $action->affected_to === null) {
            return;
        }
        $this->outbox->record(DomainEventTypes::EMPLOYMENT_RETROACTIVE_CHANGE, 'employment', $employment->id, [
            'employment_id' => $employment->id, 'personnel_action_id' => $action->id, 'kind' => $action->kind,
            'affected_from' => $action->affected_from->toDateString(), 'affected_to' => $action->affected_to->toDateString(),
            'closed_period_until' => $this->closedUntil($employment),
        ]);
    }

    /**
     * Rango retroactivo de un cambio con fecha pasada y control de periodos de planilla cerrados (RN-HIS-05).
     *
     * @return array{is_retroactive: bool, affected_from: string|null, affected_to: string|null}
     */
    private function retroactivity(Employment $employment, string $from, bool $canCorrect): array
    {
        $closedUntil = $this->closedUntil($employment);
        if ($closedUntil !== null && $from <= $closedUntil && ! $canCorrect) {
            throw new AyniException('closed_period', 422, (string) trans('people.movements.closed_period', ['date' => $closedUntil]));
        }
        $today = $this->today();
        if ($from >= $today) {
            return ['is_retroactive' => false, 'affected_from' => null, 'affected_to' => null];
        }

        return ['is_retroactive' => true, 'affected_from' => $from, 'affected_to' => Carbon::parse($today)->subDay()->toDateString()];
    }

    /** Último día de planilla cerrado para la relación (grupo de planilla o, sin grupo, la empresa). */
    private function closedUntil(Employment $employment): ?string
    {
        $groups = DB::table('payroll_group_members')->where('employment_id', $employment->id)->pluck('payroll_group_id')->all();
        $closedUntil = DB::table('payroll_periods as p')->join('payroll_groups as g', 'g.id', '=', 'p.payroll_group_id')
            ->where('p.kind', 'regular')->whereIn('p.status', ['closed', 'paid', 'accounted'])
            ->when($groups !== [], fn ($q) => $q->whereIn('p.payroll_group_id', $groups), fn ($q) => $q->where('g.legal_entity_id', $employment->legal_entity_id))
            ->max('p.ends_on');

        return $closedUntil === null ? null : (string) $closedUntil;
    }

    /** @return list<string> */
    private function approvalKinds(Employment $employment): array
    {
        $kinds = $this->parameters->value('movimientos.tipos_con_aprobacion', $employment->legal_entity_id);

        return is_array($kinds) ? array_values(array_map('strval', $kinds)) : [];
    }

    private function assertNoOpenAction(Employment $employment): void
    {
        if (PersonnelAction::query()->where('employment_id', $employment->id)->whereIn('status', self::OPEN_STATES)->exists()) {
            throw new AyniException('pending_movement', 409, (string) trans('people.movements.pending_movement'));
        }
    }

    private function assertCan(PersonnelAction $action, string $transition): void
    {
        if (! $this->machine->can($action->status, $transition)) {
            $this->machine->getNextState($action->status, $transition);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(PersonnelAction $action, string $status, array $extra = []): void
    {
        $before = $action->status;
        $action->forceFill(['status' => $status, 'lock_version' => $action->lock_version + 1] + $extra)->save();
        $this->audit->record('personnel_action.'.$status, 'personnel_action', $action->id, ['status' => $before], ['status' => $status]);
    }

    private function markCancelled(PersonnelAction $action, string $reason, ?string $userId): void
    {
        $this->transition($action, $this->machine->getNextState($action->status, PersonnelActionStateMachine::CANCEL),
            ['cancelled_at' => now(), 'cancelled_by_user_id' => $userId, 'decision_reason' => $reason !== '' ? $reason : null]);
        $this->outbox->record(DomainEventTypes::PERSONNEL_ACTION_CANCELLED, 'personnel_action', $action->id, [
            'personnel_action_id' => $action->id, 'employment_id' => $action->employment_id, 'kind' => $action->kind,
        ]);
    }

    private function employment(PersonnelAction $action): Employment
    {
        /** @var Employment */
        return Employment::query()->whereKey($action->employment_id)->firstOrFail();
    }

    private function today(): string
    {
        return Carbon::today()->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(PersonnelAction $action): array
    {
        return [
            'employment_id' => $action->employment_id, 'kind' => $action->kind, 'effective_on' => $action->effective_on->toDateString(),
            'status' => $action->status, 'changes' => $action->changes,
        ];
    }

    /**
     * Valores con su nombre visible (puesto, unidad, sede, centro, posición, jefe).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function labelled(array $values): array
    {
        $tables = ['job_profile_id' => ['job_profiles', 'name'], 'org_unit_id' => ['org_units', 'name'], 'site_id' => ['sites', 'name'],
            'cost_center_id' => ['cost_centers', 'name'], 'position_id' => ['positions', 'title']];
        $out = [];
        foreach ($values as $field => $value) {
            $label = null;
            if (is_string($value) && isset($tables[$field])) {
                $label = DB::table($tables[$field][0])->where('id', $value)->value($tables[$field][1]);
            } elseif (is_string($value) && $field === 'manager_employment_id') {
                $manager = DB::table('employments as m')->join('people as p', 'p.id', '=', 'm.person_id')->where('m.id', $value)
                    ->first(['p.first_names', 'p.preferred_name', 'p.paternal_surname', 'p.maternal_surname']);
                $label = $manager === null ? null : EmploymentPresenter::name($manager);
            }
            $out[$field] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }
}
