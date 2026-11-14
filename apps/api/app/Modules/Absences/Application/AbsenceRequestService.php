<?php

namespace App\Modules\Absences\Application;

use App\Modules\Absences\Domain\AbsenceRequestStateMachine;
use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Modules\Absences\Infrastructure\Models\AbsenceTypeRule;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Workflow\ApprovalRequests;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AUS-2 · Solicitud y registro de ausencias (ME-16) con las reglas vigentes del tipo (AUS-1).
 *
 * 1) El colaborador o su jefe registra la ausencia en borrador (tipo, fechas u horas, motivo) y adjunta
 *    el sustento. 2) Al enviarla se validan las reglas vigentes a la fecha de inicio: a quién aplica, quién
 *    puede pedirla, anticipación, tope del periodo (RN-AUS-07), duración de ley (RN-AUS-04), periodo de
 *    planilla cerrado (RN-AUS-08) y solapes con otras ausencias. 3) Si el tipo exige sustento y falta,
 *    queda «pendiente de sustento» por el plazo del tipo (RN-AUS-01); con el sustento arranca el flujo
 *    `absence_request`. 4) El motor devuelve la decisión por AbsenceRequestOutcome en la misma transacción.
 *
 * RRHH (time.absences.create_for_others) la registra directamente, sin flujo: puede autorizar excepciones
 * con motivo y registrar en un periodo cerrado (su efecto va como ajuste al periodo abierto).
 *
 * Una ausencia aprobada ocupa el calendario del equipo (AbsenceCalendarSource) y asistencia la lee por el
 * puerto Shared\Time\ApprovedAbsences para no marcar falta. El sistema la inicia y finaliza cada día.
 */
final class AbsenceRequestService
{
    public const SUBJECT_TYPE = AbsenceRequest::SUBJECT;

    /** Quién actúa: el titular (autoservicio), su jefe o RRHH (AbsenceTypeRules::REQUESTERS). */
    public const ROLES = ['employee', 'manager', 'hr'];

    /** Estados de la relación laboral que admiten ausencias. */
    private const EMPLOYMENT_STATES = ['active', 'suspended', 'terminating'];

    /** Sustentos aceptados (certificado, constancia, acta). */
    public const DOCUMENT_MIMES = ['application/pdf', 'image/png', 'image/jpeg'];

    private const EVENTS = [
        'requested' => DomainEventTypes::ABSENCE_REQUESTED,
        'approved' => DomainEventTypes::ABSENCE_APPROVED,
        'rejected' => DomainEventTypes::ABSENCE_REJECTED,
        'cancelled' => DomainEventTypes::ABSENCE_CANCELLED,
        'started' => DomainEventTypes::ABSENCE_STARTED,
        'ended' => DomainEventTypes::ABSENCE_ENDED,
    ];

    public function __construct(
        private readonly AbsenceRequestStateMachine $machine,
        private readonly AbsenceTypeCatalog $types,
        private readonly AbsenceDays $days,
        private readonly ApprovalRequests $approvals,
        private readonly FileService $files,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
        private readonly LegalLeave $legalLeave,
        private readonly MedicalLeaveSplit $split,
    ) {}

    // ------------------------------------------------------------------ Borrador

    /**
     * @param  array{employment_id: string, absence_type_id: string, starts_on: string, ends_on?: string|null, start_time?: string|null, end_time?: string|null, reason?: string|null, exception_reason?: string|null, legal_extensions?: list<string>|null}  $input
     */
    public function create(array $input, string $userId): AbsenceRequest
    {
        $type = $this->type($input['absence_type_id']);
        $values = $this->shape($type, $input['employment_id'], $input);

        return DB::transaction(function () use ($values, $input, $userId): AbsenceRequest {
            $absence = new AbsenceRequest($values + [
                'employment_id' => $input['employment_id'],
                'absence_type_id' => $input['absence_type_id'],
                'reason' => self::text($input['reason'] ?? null),
                'exception_reason' => self::text($input['exception_reason'] ?? null),
                'requested_by_user_id' => $userId,
                'status' => 'draft',
            ]);
            $absence->save();
            $this->audit->record('absence.created', self::SUBJECT_TYPE, $absence->id, null, $this->auditable($absence));

            return $absence->refresh();
        });
    }

    /**
     * Editar el borrador: fechas, horas, tipo o motivo. Fuera del borrador, 409.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(AbsenceRequest $absence, array $input): AbsenceRequest
    {
        if ($absence->status !== 'draft') {
            throw new AyniException('invalid_transition', 409, (string) trans('absences.requests.only_draft'), [], ['current_status' => $absence->status, 'allowed_actions' => []]);
        }
        $typeId = (string) ($input['absence_type_id'] ?? $absence->absence_type_id);
        $type = $this->type($typeId);
        $merged = [
            'starts_on' => $input['starts_on'] ?? $absence->day('starts_on'),
            'ends_on' => array_key_exists('ends_on', $input) ? $input['ends_on'] : $absence->day('ends_on'),
            'start_time' => array_key_exists('start_time', $input) ? $input['start_time'] : self::time($absence->start_time),
            'end_time' => array_key_exists('end_time', $input) ? $input['end_time'] : self::time($absence->end_time),
            'legal_extensions' => array_key_exists('legal_extensions', $input) ? $input['legal_extensions'] : $absence->legal_extensions,
        ];
        $values = $this->shape($type, $absence->employment_id, $merged);

        return DB::transaction(function () use ($absence, $input, $values, $typeId): AbsenceRequest {
            $before = $this->auditable($absence);
            $absence->fill($values + ['absence_type_id' => $typeId]);
            foreach (['reason', 'exception_reason'] as $field) {
                if (array_key_exists($field, $input)) {
                    $absence->setAttribute($field, self::text(is_string($input[$field]) ? $input[$field] : null));
                }
            }
            $absence->lock_version++;
            $absence->save();
            $this->audit->record('absence.updated', self::SUBJECT_TYPE, $absence->id, $before, $this->auditable($absence));

            return $absence->refresh();
        });
    }

    // ------------------------------------------------------------------ Validación (simulación incluida)

    /**
     * Mide la ausencia y revisa todas las reglas sin escribir nada. Lo usa la simulación de la pantalla de
     * solicitud y, lanzando el primer problema, el envío y el registro.
     *
     * @param  array{starts_on: string, ends_on?: string|null, start_time?: string|null, end_time?: string|null, exception_reason?: string|null, legal_extensions?: list<string>|null}  $input
     * @return array{unit: string, ends_on: string, return_on: string|null, days: string|null, hours: string|null, calendar_days: int, working_days: int, holidays: list<string>, is_paid: bool, affects_attendance: bool, document_required: bool, document_grace_days: int|null, legal_duration: array<string, mixed>|null, legal_leave: array<string, mixed>|null, required_documents: list<array{code: string, label: string}>, subsidy: array<string, mixed>|null, issues: list<array{code: string, status: int, message: string}>, warnings: list<array{code: string, message: string}>}
     */
    public function evaluate(AbsenceType $type, string $employmentId, array $input, string $role, ?string $ignoreId = null): array
    {
        $issues = [];
        $warnings = [];
        $issue = function (string $code, int $status, string $message) use (&$issues): void {
            $issues[] = ['code' => $code, 'status' => $status, 'message' => $message];
        };

        $values = $this->shape($type, $employmentId, $input);
        $startsOn = (string) $values['starts_on'];
        $endsOn = (string) $values['ends_on'];
        $employment = $this->employment($employmentId);
        $rule = $this->rule($type, $startsOn);
        $domain = AbsenceTypeCatalog::domain($rule);
        $legal = $this->types->present($type, $startsOn)['legal_duration'];
        /** @var array{base_parameter: string|null, base_days: string|null, extensions: list<array<string, mixed>>, total_days: string|null, pending_validation: bool, verified: bool, documents: list<array{code: string, label: string}>}|null $plan */
        $plan = $values['legal_leave'];

        if ($type->status !== 'active') {
            $issue('type_archived', 422, (string) trans('absences.requests.type_archived'));
        }
        if (! $domain->appliesTo($employment->legal_entity_id, $this->regime($employmentId, $startsOn))) {
            $issue('type_not_applicable', 422, (string) trans('absences.requests.type_not_applicable'));
        }
        if (! $domain->canBeRequestedBy($role)) {
            $issue('not_requestable', 403, (string) trans('absences.requests.not_requestable.'.$rule->requestable_by));
        }
        if (! in_array((string) $employment->status, self::EMPLOYMENT_STATES, true)) {
            $issue('employment_not_active', 422, (string) trans('absences.requests.employment_not_active'));
        }
        $hire = substr((string) $employment->hire_date, 0, 10);
        $end = $employment->termination_date === null ? null : substr((string) $employment->termination_date, 0, 10);
        if ($startsOn < $hire || ($end !== null && $endsOn > $end)) {
            $issue('outside_employment', 422, (string) trans('absences.requests.outside_employment'));
        }
        $quantity = $rule->unit === 'hours' ? (string) $values['hours'] : (string) $values['days'];
        if (BigDecimal::of($quantity)->isZero()) {
            $issue('no_working_days', 422, (string) trans($rule->unit === 'hours' ? 'absences.requests.no_hours' : 'absences.requests.no_working_days'));
        }

        // Anticipación (RRHH no la necesita: registra lo que ya ocurrió).
        $today = now()->toDateString();
        if ($role !== 'hr' && $rule->min_notice_days !== null && $rule->min_notice_days > 0) {
            $notice = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($startsOn), false);
            if ($notice < $rule->min_notice_days) {
                $issue('insufficient_notice', 422, (string) trans('absences.requests.insufficient_notice', ['days' => $rule->min_notice_days]));
            }
        }

        // RN-AUS-07 · Tope del periodo: fuera de él solo RRHH registra, con motivo de excepción.
        if ($rule->max_quantity !== null) {
            $used = $this->used($employmentId, $type->id, $rule->max_period, $startsOn, $rule->unit, $ignoreId);
            $total = BigDecimal::of($used)->plus($quantity);
            if ($total->isGreaterThan($rule->max_quantity)) {
                $exception = self::text($input['exception_reason'] ?? null);
                if ($role === 'hr' && $exception !== null) {
                    $warnings[] = ['code' => 'limit_exception', 'message' => (string) trans('absences.requests.limit_exception', ['max' => self::number((string) $rule->max_quantity)])];
                } else {
                    $issue('limit_exceeded', 422, (string) trans($role === 'hr' ? 'absences.requests.limit_needs_reason' : 'absences.requests.limit_exceeded', [
                        'max' => self::number((string) $rule->max_quantity), 'used' => self::number($used), 'period' => trans('absences.requests.period.'.$rule->max_period),
                    ]));
                }
            }
        }

        // RN-AUS-04 · Duración de ley (base + ampliaciones por causal, AUS-4): se propone y no se admite menos;
        // si alguna pieza está por validar, solo avisa.
        if (is_array($plan) && $plan['base_parameter'] !== null && $rule->unit === 'days') {
            $required = $plan['total_days'];
            if ($required === null) {
                $warnings[] = ['code' => 'legal_duration_pending', 'message' => (string) trans('absences.requests.legal_duration_unknown')];
            } elseif (BigDecimal::of((string) $values['calendar_days'])->isLessThan($required)) {
                if ($plan['verified']) {
                    $issue('below_legal_duration', 422, (string) trans('absences.requests.below_legal_duration', ['days' => self::number($required)]));
                } else {
                    $warnings[] = ['code' => 'legal_duration_pending', 'message' => (string) trans('absences.requests.legal_duration_pending', ['days' => self::number($required)])];
                }
            } elseif (! $plan['verified']) {
                $warnings[] = ['code' => 'legal_duration_pending', 'message' => (string) trans('absences.requests.legal_duration_proposed', ['days' => self::number($required)])];
            }
        }
        if (is_array($plan) && $plan['documents'] !== []) {
            $warnings[] = ['code' => 'legal_documents', 'message' => (string) trans('absences.legal_leave.documents_required', ['documents' => implode(', ', array_map(fn (array $d): string => $d['label'], $plan['documents']))])];
        }

        // AUS-3 · RN-AUS-03 · Descanso médico: acumulado del año y desde qué día pasa a subsidio.
        $subsidy = $rule->unit === 'days' ? $this->split->preview($type, $employmentId, $startsOn, (string) $values['calendar_days'], $ignoreId) : null;
        if ($subsidy !== null && $subsidy['kind'] === 'illness') {
            if ($subsidy['threshold_days'] === null) {
                $warnings[] = ['code' => 'subsidy_threshold_pending', 'message' => (string) trans('absences.subsidies.threshold_unknown')];
            } elseif ($subsidy['reaches_threshold']) {
                $warnings[] = ['code' => 'subsidy_threshold', 'message' => (string) trans('absences.subsidies.threshold_reached', [
                    'accumulated' => $subsidy['accumulated_days'], 'threshold' => self::number($subsidy['threshold_days']), 'subsidized' => $subsidy['subsidized_days'],
                ])];
            }
        }

        // RN-AUS-08 · Periodo de planilla cerrado: solo RRHH, y su efecto va como ajuste al periodo abierto.
        $closed = $this->closedUntil($employmentId, $employment->legal_entity_id);
        if ($closed !== null && $startsOn <= $closed) {
            if ($role === 'hr') {
                $warnings[] = ['code' => 'closed_period_adjustment', 'message' => (string) trans('absences.requests.closed_period_adjustment', ['date' => $closed])];
            } else {
                $issue('closed_period', 422, (string) trans('absences.requests.closed_period', ['date' => $closed]));
            }
        }

        // Sin solapes con otra ausencia del mismo trabajador (también lo impide la base).
        $overlap = $this->overlapping($employmentId, (string) $values['starts_at'], (string) $values['ends_at'], $ignoreId);
        if ($overlap !== null) {
            $issue('absence_overlap', 409, (string) trans('absences.requests.overlap', ['from' => $overlap['starts_on'], 'to' => $overlap['ends_on']]));
        }

        if ($rule->requires_document && $rule->document_grace_days !== null) {
            $warnings[] = ['code' => 'document_grace', 'message' => (string) trans('absences.requests.document_grace', ['days' => $rule->document_grace_days])];
        }

        return [
            'unit' => $rule->unit,
            'ends_on' => $endsOn,
            'return_on' => $values['return_on'],
            'days' => $values['days'],
            'hours' => $values['hours'],
            'calendar_days' => (int) $values['calendar_days'],
            'working_days' => $values['working_days'],
            'holidays' => $values['holidays'],
            'is_paid' => $rule->is_paid,
            'affects_attendance' => $rule->affects_attendance,
            'document_required' => $rule->requires_document,
            'document_grace_days' => $rule->document_grace_days,
            'legal_duration' => $legal,
            'legal_leave' => $plan,
            'required_documents' => $plan['documents'] ?? [],
            'subsidy' => $subsidy,
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    // ------------------------------------------------------------------ Acciones

    /**
     * Enviar el borrador (titular o jefe): valida las reglas y, con el sustento, inicia el flujo de
     * aprobación. Sin sustento obligatorio: «pendiente de sustento» por el plazo del tipo o 422.
     *
     * @return array{absence: AbsenceRequest, warnings: list<array{code: string, message: string}>}
     */
    public function submit(AbsenceRequest $absence, string $role, string $userId): array
    {
        $this->machine->getNextState($absence->status, 'submit');
        $type = $this->type($absence->absence_type_id);
        $check = $this->check($absence, $type, $role);
        $rule = $this->rule($type, $absence->day('starts_on'));

        $result = DB::transaction(function () use ($absence, $rule, $type, $userId): AbsenceRequest {
            $locked = $this->lock($absence);
            $this->machine->getNextState($locked->status, 'submit');
            $before = $this->auditable($locked);
            $locked->forceFill(['submitted_at' => now(), 'registered_directly' => false]);
            if ($rule->requires_document && ! $this->hasDocument($locked)) {
                $this->assertGrace($rule);
                $this->transition($locked, 'request_document', ['document_due_on' => $this->dueOn($locked, $rule)]);
                $this->event('requested', $locked, $type);
            } else {
                $this->startApproval($locked, $type, $userId);
            }
            $this->audit->record('absence.submitted', self::SUBJECT_TYPE, $locked->id, $before, $this->auditable($locked));

            return $locked->refresh();
        });

        return ['absence' => $result, 'warnings' => $check['warnings']];
    }

    /**
     * RRHH registra la ausencia directamente, sin flujo. Sin el sustento obligatorio queda «pendiente de
     * sustento»; con él, aprobada (y en curso o finalizada si sus fechas ya llegaron).
     *
     * @return array{absence: AbsenceRequest, warnings: list<array{code: string, message: string}>}
     */
    public function register(AbsenceRequest $absence, string $userId): array
    {
        $this->machine->getNextState($absence->status, 'register');
        $type = $this->type($absence->absence_type_id);
        $check = $this->check($absence, $type, 'hr');
        $rule = $this->rule($type, $absence->day('starts_on'));

        $result = DB::transaction(function () use ($absence, $rule, $type, $userId): AbsenceRequest {
            $locked = $this->lock($absence);
            $this->machine->getNextState($locked->status, 'register');
            $before = $this->auditable($locked);
            $locked->forceFill(['submitted_at' => now(), 'registered_directly' => true]);
            if ($rule->requires_document && ! $this->hasDocument($locked)) {
                $this->assertGrace($rule);
                $this->transition($locked, 'request_document', ['document_due_on' => $this->dueOn($locked, $rule)]);
                $this->event('requested', $locked, $type);
            } else {
                $this->transition($locked, 'register', ['decided_at' => now()]);
                $this->event('approved', $locked, $type, ['registered_directly' => true, 'decided_by_user_id' => $userId]);
                $this->advanceOne($locked, $type);
            }
            $this->audit->record('absence.registered', self::SUBJECT_TYPE, $locked->id, $before, $this->auditable($locked));

            return $locked->refresh();
        });

        return ['absence' => $result, 'warnings' => $check['warnings']];
    }

    /**
     * Adjuntar un sustento. Si la ausencia esperaba el documento: la registrada por RRHH queda aprobada y la
     * enviada por el titular vuelve a «Solicitada» e inicia la aprobación.
     */
    public function attach(AbsenceRequest $absence, string $contents, string $name, string $mime, string $label, string $userId): AbsenceRequest
    {
        if (! in_array($mime, self::DOCUMENT_MIMES, true)) {
            throw ValidationException::withMessages(['file' => [(string) trans('absences.requests.document_type')]]);
        }
        if (! in_array($absence->status, ['draft', 'pending', 'pending_document', 'approved', 'in_progress'], true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('absences.requests.document_closed'), [], ['current_status' => $absence->status, 'allowed_actions' => []]);
        }

        return DB::transaction(function () use ($absence, $contents, $name, $mime, $label, $userId): AbsenceRequest {
            $locked = $this->lock($absence);
            $file = $this->files->upload($contents, $name, $mime, $locked->tenant_id, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);
            DB::table('attachments')->insert([
                'tenant_id' => $locked->tenant_id, 'stored_file_id' => $file->id, 'subject_type' => self::SUBJECT_TYPE,
                'subject_id' => $locked->id, 'label' => $label,
            ]);
            $this->audit->record('absence.document_attached', self::SUBJECT_TYPE, $locked->id, null, ['stored_file_id' => $file->id, 'label' => $label]);

            if ($locked->status === 'pending_document') {
                $type = $this->type($locked->absence_type_id);
                if ($locked->registered_directly) {
                    $this->transition($locked, 'confirm_document', ['decided_at' => now(), 'document_due_on' => null]);
                    $this->event('approved', $locked, $type, ['registered_directly' => true, 'decided_by_user_id' => $userId]);
                    $this->advanceOne($locked, $type);
                } else {
                    $locked->forceFill(['document_due_on' => null]);
                    $this->startApproval($locked, $type, $userId, 'attach_document');
                }
            }

            return $locked->refresh();
        });
    }

    /**
     * Cancelar antes del inicio (titular o RRHH). Una ausencia en aprobación anula también sus pasos.
     */
    public function cancel(AbsenceRequest $absence, ?string $reason, string $userId): AbsenceRequest
    {
        $this->assertCancellable($absence);
        if ($absence->status !== 'draft' && self::text($reason) === null) {
            throw ValidationException::withMessages(['reason' => [(string) trans('absences.requests.reason_required')]]);
        }

        return DB::transaction(function () use ($absence, $reason, $userId): AbsenceRequest {
            $locked = $this->lock($absence);
            $this->assertCancellable($locked);
            if ($locked->workflow_instance_id !== null && $locked->status === 'pending') {
                // El motor anula los pasos pendientes y avisa al módulo (cancelled): queda idempotente.
                $this->approvals->cancel($locked->workflow_instance_id, $reason);
                $locked->refresh();
            }
            if ($locked->status !== 'cancelled') {
                $this->applyCancel($locked, $reason, $userId);
            }

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ Resultado del flujo (WFL-4)

    public function approvedByFlow(string $id): void
    {
        $absence = AbsenceRequest::query()->whereKey($id)->lockForUpdate()->first();
        if (! $absence instanceof AbsenceRequest || $absence->status !== 'pending') {
            return;
        }
        $type = $this->type($absence->absence_type_id);
        $before = $this->auditable($absence);
        $this->transition($absence, 'approve', ['decided_at' => now()]);
        $this->event('approved', $absence, $type, ['registered_directly' => false]);
        $this->audit->record('absence.approved', self::SUBJECT_TYPE, $absence->id, $before, $this->auditable($absence));
        $this->advanceOne($absence, $type);
    }

    public function rejectedByFlow(string $id, ?string $reason): void
    {
        $absence = AbsenceRequest::query()->whereKey($id)->lockForUpdate()->first();
        if (! $absence instanceof AbsenceRequest || $absence->status !== 'pending') {
            return;
        }
        $before = $this->auditable($absence);
        $this->transition($absence, 'reject', ['decided_at' => now(), 'decision_reason' => self::text($reason)]);
        $this->event('rejected', $absence, $this->type($absence->absence_type_id), ['reason' => self::text($reason)]);
        $this->audit->record('absence.rejected', self::SUBJECT_TYPE, $absence->id, $before, $this->auditable($absence), $reason);
    }

    public function cancelledByFlow(string $id, ?string $reason): void
    {
        $absence = AbsenceRequest::query()->whereKey($id)->lockForUpdate()->first();
        if ($absence instanceof AbsenceRequest && $this->machine->can($absence->status, 'cancel')) {
            $this->applyCancel($absence, $reason, null);
        }
    }

    // ------------------------------------------------------------------ Tarea diaria

    /**
     * Inicia las ausencias aprobadas que empiezan hoy, finaliza las que terminaron y rechaza las que
     * vencieron sin sustento (RN-AUS-01). Idempotente.
     *
     * @return array{started: int, ended: int, expired: int}
     */
    public function advance(?string $today = null): array
    {
        $today ??= now()->toDateString();
        $counts = ['started' => 0, 'ended' => 0, 'expired' => 0];
        $ids = AbsenceRequest::query()->where(function ($q) use ($today): void {
            $q->where(fn ($w) => $w->where('status', 'approved')->where('starts_on', '<=', $today))
                ->orWhere(fn ($w) => $w->where('status', 'in_progress')->where('ends_on', '<', $today))
                ->orWhere(fn ($w) => $w->where('status', 'pending_document')->whereNotNull('document_due_on')->where('document_due_on', '<', $today));
        })->pluck('id')->all();

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $today, &$counts): void {
                $absence = AbsenceRequest::query()->whereKey($id)->lockForUpdate()->first();
                if (! $absence instanceof AbsenceRequest) {
                    return;
                }
                $type = $this->type($absence->absence_type_id);
                if ($absence->status === 'pending_document') {
                    if ($absence->document_due_on !== null && substr((string) $absence->document_due_on, 0, 10) < $today) {
                        $reason = (string) trans('absences.requests.document_expired');
                        $this->transition($absence, 'expire_document', ['decided_at' => now(), 'decision_reason' => $reason]);
                        $this->event('rejected', $absence, $type, ['reason' => $reason]);
                        $this->audit->record('absence.document_expired', self::SUBJECT_TYPE, $absence->id, null, $this->auditable($absence));
                        $counts['expired']++;
                    }

                    return;
                }
                $result = $this->advanceOne($absence, $type, $today);
                $counts['started'] += $result['started'];
                $counts['ended'] += $result['ended'];
            });
        }

        return $counts;
    }

    // ------------------------------------------------------------------ Consultas y presentación

    /**
     * @return array<string, mixed>
     */
    public function present(AbsenceRequest $a, bool $detail = false): array
    {
        $type = AbsenceType::query()->withTrashed()->find($a->absence_type_id);
        $rule = $type instanceof AbsenceType ? $this->types->rulesAt($type, $a->day('starts_on')) : null;
        $employment = DB::table('employments as e')->leftJoin('people as p', 'p.id', '=', 'e.person_id')
            ->where('e.id', $a->employment_id)->first(['e.employee_code', 'e.person_id', 'e.legal_entity_id', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname']);
        $confidential = $type instanceof AbsenceType && $type->is_confidential;
        $attachments = $this->attachments($a);
        $definition = $type instanceof AbsenceType ? LegalLeave::definition($type->country_code ?? $this->types->country(), $type->code) : ['extensions' => [], 'documents' => []];
        $subsidyKind = $type instanceof AbsenceType ? $this->split->kind($type, $a->day('starts_on')) : null;

        $row = [
            'id' => $a->id,
            'employment_id' => $a->employment_id,
            'person_id' => $employment?->person_id,
            'person_name' => $employment === null ? null : trim($employment->first_names.' '.$employment->paternal_surname.' '.($employment->maternal_surname ?? '')),
            'employee_code' => $employment?->employee_code,
            'legal_entity_id' => $employment?->legal_entity_id,
            'absence_type_id' => $a->absence_type_id,
            'absence_type_name' => $type?->name,
            'absence_type_category' => $type?->category,
            'is_confidential' => $confidential,
            'unit' => $rule->unit ?? ($a->hours !== null ? 'hours' : 'days'),
            'starts_on' => $a->day('starts_on'),
            'ends_on' => $a->day('ends_on'),
            'start_time' => self::time($a->start_time),
            'end_time' => self::time($a->end_time),
            'days' => $a->days === null ? null : self::number((string) $a->days),
            'hours' => $a->hours === null ? null : self::number((string) $a->hours),
            'calendar_days' => $a->calendar_days === null ? null : self::number((string) $a->calendar_days),
            'return_on' => $a->return_on === null ? null : substr((string) $a->return_on, 0, 10),
            'legal_extensions' => $a->legal_extensions ?? [],
            'required_documents' => array_map(fn (string $code): array => ['code' => $code, 'label' => (string) trans('absences.legal_leave.documents.'.$code)], $definition['documents']),
            'is_paid' => $rule?->is_paid,
            'affects_attendance' => $rule?->affects_attendance,
            'status' => $a->status,
            'registered_directly' => $a->registered_directly,
            'document_required' => (bool) ($rule->requires_document ?? false),
            'document_due_on' => $a->document_due_on === null ? null : substr((string) $a->document_due_on, 0, 10),
            'has_document' => $attachments !== [],
            'exception_reason' => $a->exception_reason,
            'requested_by_user_id' => $a->requested_by_user_id,
            'requested_by_name' => $a->requested_by_user_id === null ? null : DB::table('users')->where('id', $a->requested_by_user_id)->value('display_name'),
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'decided_at' => $a->decided_at?->toIso8601String(),
            'decision_reason' => $a->decision_reason,
            'cancelled_at' => $a->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $a->cancellation_reason,
            'approval_request_id' => $a->workflow_instance_id,
            'version' => $a->lock_version,
            'created_at' => $a->created_at->toIso8601String(),
            'updated_at' => $a->updated_at->toIso8601String(),
        ];
        // RN-AUS-05 · En los tipos confidenciales (salud) el motivo y el sustento son datos sensibles: viajan
        // en campos propios que exigen time.absences.medical_certificate.view (el controlador los quita).
        if ($confidential) {
            $row['medical_reason'] = $a->reason;
            $row['medical_attachments'] = $attachments;
        } else {
            $row['reason'] = $a->reason;
            $row['attachments'] = $attachments;
        }
        // AUS-3: reparto empleador / subsidio y estado del expediente (sensibles: revelan un descanso médico).
        if ($subsidyKind !== null) {
            $row['employer_paid_days'] = $a->employer_paid_days === null ? null : self::number((string) $a->employer_paid_days);
            $row['subsidized_days'] = $a->subsidized_days === null ? null : self::number((string) $a->subsidized_days);
            $row['subsidy_status'] = DB::table('absence_subsidy_cases')->where('absence_request_id', $a->id)->whereNull('deleted_at')->value('status');
        }
        if ($detail && $a->workflow_instance_id !== null && $a->status === 'pending') {
            $row['approval'] = $this->approvals->summary($a->workflow_instance_id);
        }

        return $row;
    }

    /**
     * Archivo de un sustento de la ausencia (la descarga queda registrada).
     *
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function document(AbsenceRequest $a, string $attachmentId, ?string $userId): ?array
    {
        $fileId = DB::table('attachments')->where('id', $attachmentId)->where('subject_type', self::SUBJECT_TYPE)
            ->where('subject_id', $a->id)->value('stored_file_id');

        return is_string($fileId) ? $this->files->contentsForDownload($fileId, $a->tenant_id, $userId) : null;
    }

    public function type(string $id): AbsenceType
    {
        $type = AbsenceType::query()->find($id);
        if (! $type instanceof AbsenceType) {
            throw ValidationException::withMessages(['absence_type_id' => [(string) trans('absences.requests.type_unknown')]]);
        }

        return $type;
    }

    /**
     * Acciones que el usuario puede pedir sobre la ausencia (la interfaz solo muestra; el servidor valida).
     *
     * @param  callable(string): bool  $can
     * @return list<string>
     */
    public function allowedActions(AbsenceRequest $a, callable $can, bool $isOwner): array
    {
        $out = [];
        foreach ($this->machine->manualActions($a->status) as $action) {
            $ok = match ($action) {
                'submit' => $can('time.absences.submit'),
                'register' => $can('time.absences.create_for_others'),
                'cancel' => ($isOwner || $can('time.absences.create_for_others')) && $can('time.absences.cancel') && $this->cancellable($a),
                default => false,
            };
            if ($ok) {
                $out[] = $action;
            }
        }
        if (in_array($a->status, ['draft', 'pending', 'pending_document', 'approved', 'in_progress'], true) && $can('time.absences.update')) {
            $out[] = 'attach';
        }

        return $out;
    }

    // ------------------------------------------------------------------ Internos

    /**
     * Valida forma y calcula fechas, días y horas con las reglas vigentes al inicio.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function shape(AbsenceType $type, string $employmentId, array $input): array
    {
        $startsOn = (string) $input['starts_on'];
        $endsOn = isset($input['ends_on']) && is_string($input['ends_on']) && $input['ends_on'] !== '' ? $input['ends_on'] : $startsOn;
        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => [(string) trans('absences.requests.ends_before_start')]]);
        }
        $rule = $this->rule($type, $startsOn);
        $startTime = isset($input['start_time']) && is_string($input['start_time']) && $input['start_time'] !== '' ? substr($input['start_time'], 0, 5) : null;
        $endTime = isset($input['end_time']) && is_string($input['end_time']) && $input['end_time'] !== '' ? substr($input['end_time'], 0, 5) : null;
        if ($rule->unit === 'hours') {
            if ($startTime === null || $endTime === null) {
                throw ValidationException::withMessages(['start_time' => [(string) trans('absences.requests.hours_required')]]);
            }
            if ($endsOn !== $startsOn) {
                throw ValidationException::withMessages(['ends_on' => [(string) trans('absences.requests.hours_same_day')]]);
            }
            if ($endTime <= $startTime) {
                throw ValidationException::withMessages(['end_time' => [(string) trans('absences.requests.hours_order')]]);
            }
        } else {
            $startTime = null;
            $endTime = null;
        }

        // AUS-4 · Licencia de ley: duración desde los parámetros (base + causales); sin fecha de fin se propone.
        $extensions = self::extensions($input['legal_extensions'] ?? null);
        $plan = $rule->unit === 'days' ? $this->legalLeave->plan($type, $startsOn, $extensions, $this->regime($employmentId, $startsOn)) : null;
        $explicitEnd = isset($input['ends_on']) && is_string($input['ends_on']) && $input['ends_on'] !== '';
        if (! $explicitEnd && $plan !== null) {
            $endsOn = LegalLeave::proposedEnd($plan, $startsOn) ?? $endsOn;
        }

        $context = $this->context($employmentId, $startsOn);
        $m = $this->days->measure($context, $startsOn, $endsOn, $startTime, $endTime);
        $days = $rule->unit === 'hours' ? null : (string) ($rule->day_count === 'working' ? $m['working_days'] : $m['calendar_days']);

        return [
            'starts_on' => $startsOn, 'ends_on' => $endsOn, 'start_time' => $startTime, 'end_time' => $endTime,
            'starts_at' => $m['starts_at'], 'ends_at' => $m['ends_at'],
            'days' => $days, 'hours' => $m['hours'], 'calendar_days' => (string) $m['calendar_days'],
            'legal_extensions' => $extensions === [] ? null : $extensions,
            'return_on' => $rule->unit === 'days' ? $this->days->returnOn($context, $endsOn) : null,
            'working_days' => $m['working_days'], 'holidays' => $m['holidays'], 'legal_leave' => $plan,
        ];
    }

    /**
     * @return array{warnings: list<array{code: string, message: string}>}
     */
    private function check(AbsenceRequest $absence, AbsenceType $type, string $role): array
    {
        $result = $this->evaluate($type, $absence->employment_id, [
            'starts_on' => $absence->day('starts_on'), 'ends_on' => $absence->day('ends_on'),
            'start_time' => self::time($absence->start_time), 'end_time' => self::time($absence->end_time),
            'exception_reason' => $absence->exception_reason, 'legal_extensions' => $absence->legal_extensions,
        ], $role, $absence->id);
        if ($result['issues'] !== []) {
            $first = $result['issues'][0];
            throw new AyniException($first['code'], $first['status'], $first['message'], [], ['issues' => $result['issues']]);
        }
        // El cómputo vigente al enviar es el que queda (los feriados pudieron cambiar desde el borrador).
        $absence->forceFill(['days' => $result['days'], 'hours' => $result['hours'], 'calendar_days' => (string) $result['calendar_days'], 'return_on' => $result['return_on']]);

        return ['warnings' => $result['warnings']];
    }

    private function startApproval(AbsenceRequest $locked, AbsenceType $type, string $userId, string $action = 'submit'): void
    {
        $next = $this->machine->getNextState($locked->status, $action);
        $locked->forceFill(['status' => $next]);
        $this->save($locked);
        $rule = $this->rule($type, $locked->day('starts_on'));
        $owner = $this->ownerUserId($locked->employment_id);
        $approvalId = $this->approvals->start(self::SUBJECT_TYPE, $locked->id, $owner ?? $userId, [
            'legal_entity_id' => DB::table('employments')->where('id', $locked->employment_id)->value('legal_entity_id'),
            'employment_id' => $locked->employment_id,
            'absence_type_id' => $type->id,
            'absence_type_code' => $type->code,
            'category' => $type->category,
            'is_paid' => $rule->is_paid,
            'days' => $locked->days,
            'hours' => $locked->hours,
            'starts_on' => $locked->day('starts_on'),
            'ends_on' => $locked->day('ends_on'),
            'return_on' => $locked->return_on === null ? null : substr((string) $locked->return_on, 0, 10),
            'workflow_definition_id' => $rule->approval_workflow_definition_id,
            'submitted_by_user_id' => $userId,
        ]);
        // El motor pudo decidir en el acto (flujo sin aprobadores): no se pisa ese resultado.
        $locked->refresh();
        $locked->forceFill(['workflow_instance_id' => $approvalId]);
        $this->save($locked);
        $this->event('requested', $locked, $type, ['approval_request_id' => $approvalId]);
    }

    /**
     * Inicia o finaliza según la fecha.
     *
     * @return array{started: int, ended: int}
     */
    private function advanceOne(AbsenceRequest $absence, AbsenceType $type, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $out = ['started' => 0, 'ended' => 0];
        if ($absence->status === 'approved' && $absence->day('starts_on') <= $today) {
            $this->transition($absence, 'start');
            $this->event('started', $absence, $type);
            $out['started']++;
        }
        if ($absence->status === 'in_progress' && $absence->day('ends_on') < $today) {
            $this->transition($absence, 'finish');
            $this->event('ended', $absence, $type);
            $out['ended']++;
        }

        return $out;
    }

    private function applyCancel(AbsenceRequest $absence, ?string $reason, ?string $userId): void
    {
        $before = $this->auditable($absence);
        $this->transition($absence, 'cancel', [
            'cancelled_at' => now(), 'cancelled_by_user_id' => $userId, 'cancellation_reason' => self::text($reason),
        ]);
        $this->event('cancelled', $absence, $this->type($absence->absence_type_id), ['reason' => self::text($reason)]);
        $this->audit->record('absence.cancelled', self::SUBJECT_TYPE, $absence->id, $before, $this->auditable($absence), $reason);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(AbsenceRequest $absence, string $action, array $extra = []): void
    {
        $next = $this->machine->getNextState($absence->status, $action);
        $absence->forceFill(['status' => $next] + $extra);
        $this->save($absence);
    }

    /** Guarda traduciendo el solape (exclusión de la base) a un 409 claro. */
    private function save(AbsenceRequest $absence): void
    {
        $absence->lock_version++;
        try {
            DB::transaction(fn () => $absence->save());
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw new AyniException('absence_overlap', 409, (string) trans('absences.requests.overlap_generic'));
            }
            throw $e;
        }
    }

    private function lock(AbsenceRequest $absence): AbsenceRequest
    {
        /** @var AbsenceRequest $locked */
        $locked = AbsenceRequest::query()->whereKey($absence->id)->lockForUpdate()->firstOrFail();
        // Lo recalculado al validar (días con los feriados vigentes) se conserva.
        foreach (['days', 'hours', 'calendar_days', 'return_on'] as $field) {
            if ($absence->isDirty($field)) {
                $locked->setAttribute($field, $absence->getAttribute($field));
            }
        }

        return $locked;
    }

    private function assertGrace(AbsenceTypeRule $rule): void
    {
        if ($rule->document_grace_days === null) {
            throw new AyniException('document_required', 422, (string) trans('absences.requests.document_required'));
        }
    }

    private function dueOn(AbsenceRequest $absence, AbsenceTypeRule $rule): string
    {
        $base = max(now()->toDateString(), $absence->day('starts_on'));

        return CarbonImmutable::parse($base)->addDays((int) $rule->document_grace_days)->toDateString();
    }

    private function cancellable(AbsenceRequest $a): bool
    {
        if (! $this->machine->can($a->status, 'cancel')) {
            return false;
        }

        // Una ausencia aprobada se cancela solo antes de su inicio.
        return $a->status !== 'approved' || $a->day('starts_on') > now()->toDateString();
    }

    private function assertCancellable(AbsenceRequest $a): void
    {
        $this->machine->getNextState($a->status, 'cancel');
        if (! $this->cancellable($a)) {
            throw new AyniException('invalid_transition', 409, (string) trans('absences.requests.cancel_started'), [], ['current_status' => $a->status, 'allowed_actions' => []]);
        }
    }

    private function hasDocument(AbsenceRequest $a): bool
    {
        return DB::table('attachments')->where('subject_type', self::SUBJECT_TYPE)->where('subject_id', $a->id)->exists();
    }

    /**
     * @return list<array{id: string, label: string|null, name: string, mime: string, size_bytes: int, uploaded_at: string}>
     */
    private function attachments(AbsenceRequest $a): array
    {
        return array_values(DB::table('attachments as t')->join('stored_files as f', 'f.id', '=', 't.stored_file_id')
            ->where('t.subject_type', self::SUBJECT_TYPE)->where('t.subject_id', $a->id)->whereNull('f.deleted_at')
            ->orderBy('t.created_at')->get(['t.id', 't.label', 'f.original_name', 'f.mime_type', 'f.size_bytes', 't.created_at'])
            ->map(fn ($r): array => [
                'id' => (string) $r->id, 'label' => $r->label === null ? null : (string) $r->label, 'name' => (string) $r->original_name,
                'mime' => (string) $r->mime_type, 'size_bytes' => (int) $r->size_bytes, 'uploaded_at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601String(),
            ])->all());
    }

    private function rule(AbsenceType $type, string $asOf): AbsenceTypeRule
    {
        $rule = $this->types->rulesAt($type, $asOf);
        if (! $rule instanceof AbsenceTypeRule) {
            throw ValidationException::withMessages(['starts_on' => [(string) trans('absences.requests.no_rules', ['date' => $asOf])]]);
        }

        return $rule;
    }

    /**
     * @return object{status: string, hire_date: string, termination_date: string|null, legal_entity_id: string, person_id: string}
     */
    private function employment(string $id): object
    {
        $row = DB::table('employments')->where('id', $id)->whereNull('deleted_at')->first(['status', 'hire_date', 'termination_date', 'legal_entity_id', 'person_id']);
        if ($row === null) {
            throw ValidationException::withMessages(['employment_id' => [(string) trans('absences.requests.employment_unknown')]]);
        }

        /** @var object{status: string, hire_date: string, termination_date: string|null, legal_entity_id: string, person_id: string} */
        return $row;
    }

    /**
     * Empresa, sede vigente a la fecha y zona horaria (sede → empresa → organización).
     *
     * @return array{legal_entity_id: string, site_id: string|null, timezone: string}
     */
    private function context(string $employmentId, string $asOf): array
    {
        $employment = $this->employment($employmentId);
        $site = DB::table('employment_assignments')->where('employment_id', $employmentId)->where('valid_from', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $asOf))->value('site_id');
        $siteId = $site === null ? null : (string) $site;
        $tz = $siteId === null ? null : DB::table('sites')->where('id', $siteId)->value('timezone');
        $tz ??= DB::table('legal_entities')->where('id', $employment->legal_entity_id)->value('timezone');
        $tz ??= DB::table('current_tenant')->value('timezone');

        return ['legal_entity_id' => $employment->legal_entity_id, 'site_id' => $siteId, 'timezone' => is_string($tz) && $tz !== '' ? $tz : (string) config('app.timezone')];
    }

    private function regime(string $employmentId, string $asOf): ?string
    {
        $code = DB::table('employment_labor_regimes')->where('employment_id', $employmentId)->where('valid_from', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $asOf))->value('labor_regime_code');

        return $code === null ? null : (string) $code;
    }

    /** Cantidad ya usada del tipo en el periodo de la fecha (días o horas según la unidad). */
    private function used(string $employmentId, string $typeId, string $period, string $startsOn, string $unit, ?string $ignoreId): string
    {
        if ($period === 'event') {
            return '0';
        }
        $d = CarbonImmutable::parse($startsOn);
        [$from, $to] = $period === 'month'
            ? [$d->startOfMonth()->toDateString(), $d->endOfMonth()->toDateString()]
            : [$d->startOfYear()->toDateString(), $d->endOfYear()->toDateString()];
        $sum = AbsenceRequest::query()->where('employment_id', $employmentId)->where('absence_type_id', $typeId)
            ->whereIn('status', AbsenceRequestStateMachine::ACTIVE_STATES)->whereBetween('starts_on', [$from, $to])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->sum($unit === 'hours' ? 'hours' : 'days');

        return (string) BigDecimal::of((string) ($sum ?: '0'));
    }

    /**
     * @return array{starts_on: string, ends_on: string}|null
     */
    private function overlapping(string $employmentId, string $startsAt, string $endsAt, ?string $ignoreId): ?array
    {
        $row = AbsenceRequest::query()->where('employment_id', $employmentId)->whereIn('status', AbsenceRequestStateMachine::ACTIVE_STATES)
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->first();

        return $row instanceof AbsenceRequest ? ['starts_on' => $row->day('starts_on'), 'ends_on' => $row->day('ends_on')] : null;
    }

    /** Último día de planilla cerrado para la relación (grupo de planilla o, sin grupo, la empresa). */
    private function closedUntil(string $employmentId, string $legalEntityId): ?string
    {
        $groups = DB::table('payroll_group_members')->where('employment_id', $employmentId)->pluck('payroll_group_id')->all();
        $closed = DB::table('payroll_periods as p')->join('payroll_groups as g', 'g.id', '=', 'p.payroll_group_id')
            ->where('p.kind', 'regular')->whereIn('p.status', ['closed', 'paid', 'accounted'])
            ->when($groups !== [], fn ($q) => $q->whereIn('p.payroll_group_id', $groups), fn ($q) => $q->where('g.legal_entity_id', $legalEntityId))
            ->max('p.ends_on');

        return $closed === null ? null : substr((string) $closed, 0, 10);
    }

    private function ownerUserId(string $employmentId): ?string
    {
        $person = DB::table('employments')->where('id', $employmentId)->value('person_id');
        $user = $person === null ? null : DB::table('users')->where('person_id', $person)->whereNull('deleted_at')->value('id');

        return $user === null ? null : (string) $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function event(string $kind, AbsenceRequest $a, AbsenceType $type, array $extra = []): void
    {
        $rule = $this->types->rulesAt($type, $a->day('starts_on'));
        $this->outbox->record(self::EVENTS[$kind], self::SUBJECT_TYPE, $a->id, [
            'absence_request_id' => $a->id,
            'employment_id' => $a->employment_id,
            'absence_type_id' => $type->id,
            'absence_type_code' => $type->code,
            'category' => $type->category,
            'starts_on' => $a->day('starts_on'),
            'ends_on' => $a->day('ends_on'),
            'start_time' => self::time($a->start_time),
            'end_time' => self::time($a->end_time),
            'days' => $a->days === null ? null : self::number((string) $a->days),
            'hours' => $a->hours === null ? null : self::number((string) $a->hours),
            'is_paid' => $rule?->is_paid,
            'affects_attendance' => $rule?->affects_attendance,
            'status' => $a->status,
            'tenant_id' => TenancyScope::requireTenant(),
        ] + $extra);

        // AUS-3: el reparto empleador / subsidio del año cambia cuando una ausencia entra o sale del calendario.
        if (in_array($kind, ['requested', 'approved', 'rejected', 'cancelled'], true) && $this->split->kind($type, $a->day('starts_on')) !== null) {
            $this->split->recompute($a->employment_id, (int) substr($a->day('starts_on'), 0, 4));
        }
    }

    /**
     * El motivo no va a la auditoría en claro: puede ser médico (RN-AUS-05).
     *
     * @return array<string, mixed>
     */
    private function auditable(AbsenceRequest $a): array
    {
        return [
            'employment_id' => $a->employment_id, 'absence_type_id' => $a->absence_type_id, 'status' => $a->status,
            'starts_on' => $a->day('starts_on'), 'ends_on' => $a->day('ends_on'), 'start_time' => self::time($a->start_time),
            'end_time' => self::time($a->end_time), 'days' => $a->days, 'hours' => $a->hours,
            'has_reason' => $a->reason !== null, 'registered_directly' => $a->registered_directly,
            'exception_reason' => $a->exception_reason, 'workflow_instance_id' => $a->workflow_instance_id,
        ];
    }

    /**
     * Códigos de causal de ley elegidos (sin repetidos).
     *
     * @return list<string>
     */
    private static function extensions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter($value, fn ($v): bool => is_string($v) && $v !== '')));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 5) : null;
    }

    private static function number(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
