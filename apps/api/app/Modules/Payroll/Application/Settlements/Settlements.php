<?php

namespace App\Modules\Payroll\Application\Settlements;

use App\Modules\Payroll\Domain\SettlementStateMachine;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Workflow\ApprovalRequests;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LIQ-1 a LIQ-4 · ME-23 · Ciclo de vida de la liquidación de beneficios sociales: borrador al registrarse el cese
 * (RN-LIQ-01), cálculo con traza (cada cálculo conserva sus líneas), envío a aprobación con flujo WFL si existe,
 * aprobación por alguien distinto de quien calculó (RN-LIQ-06) e inmutable (RN-LIQ-07), pago con control del plazo
 * legal (RN-LIQ-02), documentos de cese (RN-LIQ-08) y anulación antes de aprobar (cese revertido).
 */
final class Settlements
{
    public const SUBJECT_TYPE = 'settlement';

    /** Anticipación operativa del aviso de vencimiento del plazo de pago (el plazo en sí es un parámetro legal). */
    private const ALERT_LEAD_HOURS = 24;

    private const EDITABLE = ['draft', 'calculated'];

    private const OPEN = ['draft', 'calculated', 'pending_approval', 'approved'];

    public function __construct(
        private readonly SettlementCalculator $calculator,
        private readonly SettlementStateMachine $machine,
        private readonly SettlementDocuments $documents,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly ApprovalRequests $approvals,
    ) {}

    /** RN-LIQ-01 · Cese registrado: liquidación en borrador con la fecha y el motivo (idempotente por cese). */
    public function createForTermination(string $employmentId, ?string $terminationId, string $terminationDate, ?string $reasonCode): string
    {
        if ($terminationId !== null) {
            $existing = DB::table('settlements')->where('termination_id', $terminationId)->whereNull('deleted_at')->where('status', '<>', 'voided')->value('id');
            if ($existing !== null) {
                return (string) $existing;
            }
        }

        return $this->insert($employmentId, $terminationId, $terminationDate, $reasonCode, null);
    }

    /**
     * @param  array{employment_id: string, termination_date: string, reason_code?: string|null, severance_applies?: bool, include_pending_salary?: bool, deductions?: list<array<string, mixed>>}  $data
     */
    public function create(array $data, ?string $userId): string
    {
        $termination = DB::table('terminations as t')->join('termination_reasons as r', 'r.id', '=', 't.termination_reason_id')
            ->where('t.employment_id', $data['employment_id'])->whereNull('t.deleted_at')->whereNotIn('t.status', ['cancelled'])
            ->orderByDesc('t.created_at')->first(['t.id', 'r.code']);
        if ($termination !== null && DB::table('settlements')->where('termination_id', $termination->id)->whereNull('deleted_at')->where('status', '<>', 'voided')->exists()) {
            throw new AyniException('settlement_exists', 409, (string) trans('payroll.settlements.exists'));
        }
        $id = $this->insert($data['employment_id'], $termination === null ? null : (string) $termination->id, $data['termination_date'],
            $data['reason_code'] ?? ($termination === null ? null : (string) $termination->code), $data);
        $this->audit->record('settlement.created', 'settlement', $id, null, ['employment_id' => $data['employment_id'], 'termination_date' => $data['termination_date']]);

        return $id;
    }

    /** @param array<string, mixed>|null $data */
    private function insert(string $employmentId, ?string $terminationId, string $date, ?string $reasonCode, ?array $data): string
    {
        $currency = (string) (DB::table('employments as e')->join('legal_entities as l', 'l.id', '=', 'e.legal_entity_id')->where('e.id', $employmentId)->value('l.currency') ?? 'PEN');

        return (string) DB::table('settlements')->insertGetId([
            'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $employmentId, 'termination_id' => $terminationId,
            'termination_date' => $date, 'reason_code' => $reasonCode, 'status' => 'draft', 'currency' => $currency,
            'severance_applies' => (bool) ($data['severance_applies'] ?? false), 'include_pending_salary' => (bool) ($data['include_pending_salary'] ?? false),
            'inputs' => json_encode(['deductions' => array_values($data['deductions'] ?? [])]), 'calculation_number' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(\stdClass $s, array $data): void
    {
        if (! in_array($s->status, self::EDITABLE, true)) {
            throw new AyniException('settlement_locked', 409, (string) trans('payroll.settlements.locked'));
        }
        $changes = [];
        foreach (['severance_applies', 'include_pending_salary'] as $k) {
            if (array_key_exists($k, $data)) {
                $changes[$k] = (bool) $data[$k];
            }
        }
        if (array_key_exists('deductions', $data)) {
            $changes['inputs'] = json_encode(['deductions' => array_values((array) $data['deductions'])]);
        }
        if ($changes === []) {
            return;
        }
        DB::table('settlements')->where('id', $s->id)->update($changes + ['updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
        $this->audit->record('settlement.updated', 'settlement', (string) $s->id, array_intersect_key((array) $s, $changes), array_map(fn ($v) => is_string($v) ? json_decode($v, true) ?? $v : $v, $changes));
    }

    public function calculate(\stdClass $s, ?string $userId): void
    {
        $next = $this->machine->getNextState((string) $s->status, 'calculate');
        $inputs = $this->inputs($s);
        $r = $this->calculator->calculate((string) $s->employment_id, substr((string) $s->termination_date, 0, 10), $inputs);
        $deadline = $this->calculator->deadline((string) $s->employment_id, substr((string) $s->termination_date, 0, 10), $r['regime']);
        $warnings = $r['warnings'];
        if ($deadline === null) {
            $warnings[] = ['code' => 'payment_deadline_unconfirmed', 'message' => (string) trans('payroll.settlements.warnings.payment_deadline_unconfirmed')];
        }
        $reason = DB::table('termination_reasons')->where('code', $s->reason_code)->value('category');
        if ($reason === 'dismissal' && ! $inputs['severance_applies']) {
            $warnings[] = ['code' => 'severance_not_requested', 'message' => (string) trans('payroll.settlements.warnings.severance_not_requested')];
        }
        DB::transaction(function () use ($s, $next, $r, $deadline, $warnings, $userId): void {
            $number = (int) ($s->calculation_number ?? 0) + 1;
            foreach ($r['lines'] as $line) {
                DB::table('settlement_lines')->insert([
                    'tenant_id' => $s->tenant_id, 'settlement_id' => $s->id, 'kind' => $line['kind'], 'payroll_concept_id' => $line['payroll_concept_id'],
                    'quantity' => $line['quantity'], 'amount' => $line['amount']['amount'], 'currency' => $line['amount']['currency'],
                    'trace' => json_encode($line['trace']), 'concept_code' => $line['concept_code'], 'concept_name' => $line['concept_name'],
                    'concept_kind' => $line['concept_kind'], 'explanation' => $line['explanation'], 'calculation_number' => $number,
                ]);
            }
            $periods = $r['periods'] + ['payment_deadline' => $deadline, 'deductions' => $r['deductions'], 'regime' => $r['regime']];
            DB::table('settlements')->where('id', $s->id)->update([
                'status' => $next, 'calculation_number' => $number, 'total_amount' => $r['status'] === 'calculated' ? $r['totals']['net'] : null,
                'totals' => json_encode($r['totals']), 'periods' => json_encode($periods), 'errors' => json_encode($r['errors']),
                'warnings' => json_encode($warnings), 'due_on' => $deadline['due_on'] ?? null, 'due_alerted_at' => null,
                'parameters_snapshot' => json_encode(['parameters' => $r['parameters'], 'engine_version' => $r['engine_version'], 'pack_version' => $r['pack_version']]),
                'calculated_at' => now(), 'calculated_by_user_id' => $userId, 'return_reason' => null,
                'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1'),
            ]);
            $this->audit->record('settlement.calculated', 'settlement', (string) $s->id, ['status' => $s->status, 'total' => $s->total_amount],
                ['status' => $next, 'total' => $r['totals']['net'], 'calculation_number' => $number, 'errors' => count($r['errors'])]);
            $this->outbox->record(DomainEventTypes::SETTLEMENT_CALCULATED, 'settlement', (string) $s->id, [
                'settlement_id' => (string) $s->id, 'employment_id' => (string) $s->employment_id, 'termination_date' => substr((string) $s->termination_date, 0, 10)]);
        });
    }

    /**
     * @return array{severance_applies: bool, include_pending_salary: bool, deductions: list<array{kind: string, amount: string, description?: string|null}>}
     */
    public function inputs(\stdClass $s): array
    {
        $raw = json_decode((string) ($s->inputs ?? '{}'), true);

        return ['severance_applies' => (bool) $s->severance_applies, 'include_pending_salary' => (bool) $s->include_pending_salary,
            'deductions' => array_values(is_array($raw) ? (array) ($raw['deductions'] ?? []) : [])];
    }

    /**
     * @param  array{reason?: string|null, beneficiary_reference?: string|null, paid_on?: string, payment_reference?: string, delivery_method?: string, confirm_physical_signature?: bool}  $input
     */
    public function transition(\stdClass $s, string $action, ?string $userId, array $input = [], bool $fromFlow = false): void
    {
        $before = (string) $s->status;
        $next = $this->machine->getNextState($before, $action);
        $reason = isset($input['reason']) && trim($input['reason']) !== '' ? trim($input['reason']) : null;
        $changes = [];
        $event = null;
        $startFlow = false;
        switch ($action) {
            case 'submit':
                if (json_decode((string) ($s->errors ?? '[]'), true) !== [] || $s->total_amount === null) {
                    throw new AyniException('settlement_has_errors', 409, (string) trans('payroll.settlements.has_errors'));
                }
                $changes = ['submitted_at' => now(), 'submitted_by_user_id' => $userId, 'return_reason' => null];
                $startFlow = $this->hasFlow();
                break;
            case 'approve':
                if (! $fromFlow && $this->runningApproval((string) $s->id) !== null) {
                    throw new AyniException('managed_by_flow', 409, (string) trans('payroll.settlements.managed_by_flow'));
                }
                // RN-LIQ-06 · Segregación de funciones: quien calculó no aprueba.
                if ($userId !== null && $userId === $s->calculated_by_user_id) {
                    throw new AyniException('segregation_of_duties', 409, (string) trans('payroll.settlements.segregation'));
                }
                $changes = ['approved_by_user_id' => $userId, 'approved_at' => now()];
                $event = [DomainEventTypes::SETTLEMENT_APPROVED, ['approved_by' => $userId]];
                break;
            case 'return':
                if (! $fromFlow && $this->runningApproval((string) $s->id) !== null) {
                    throw new AyniException('managed_by_flow', 409, (string) trans('payroll.settlements.managed_by_flow'));
                }
                if ($reason === null) {
                    throw new BusinessRuleViolatedException('reason_required', (string) trans('payroll.settlements.reason_required'));
                }
                $changes = ['return_reason' => $reason, 'submitted_at' => null, 'submitted_by_user_id' => null];
                break;
            case 'mark-paid':
                $paidOn = (string) ($input['paid_on'] ?? now()->toDateString());
                if ($paidOn < substr((string) $s->termination_date, 0, 10)) {
                    throw new BusinessRuleViolatedException('paid_before_termination', (string) trans('payroll.settlements.paid_before_termination'));
                }
                // RN-LIQ-10 · Fallecimiento: el pago va a los beneficiarios declarados y se bloquea sin su sustento.
                $category = DB::table('termination_reasons')->where('code', $s->reason_code)->value('category');
                if ($category === 'death' && trim((string) ($input['beneficiary_reference'] ?? '')) === '') {
                    throw new BusinessRuleViolatedException('beneficiaries_required', (string) trans('payroll.settlements.beneficiaries_required'));
                }
                $onTime = $s->due_on === null || $paidOn <= substr((string) $s->due_on, 0, 10);
                $changes = ['paid_on' => $paidOn, 'paid_by_user_id' => $userId, 'payment_reference' => $input['payment_reference'] ?? null];
                if (isset($input['beneficiary_reference'])) {
                    $periods = json_decode((string) ($s->periods ?? '{}'), true);
                    $changes['periods'] = json_encode((is_array($periods) ? $periods : []) + ['beneficiary_reference' => $input['beneficiary_reference']]);
                }
                $event = [DomainEventTypes::SETTLEMENT_PAID, ['paid_on' => $paidOn, 'on_time' => $onTime]];
                break;
            case 'deliver-documents':
                $this->documents->assertSigned((string) $s->id, (bool) ($input['confirm_physical_signature'] ?? false), $userId);
                $changes = ['documents_delivered_at' => now()];
                break;
            case 'void':
                if ($reason === null) {
                    throw new BusinessRuleViolatedException('reason_required', (string) trans('payroll.settlements.reason_required'));
                }
                $changes = ['void_reason' => $reason];
                $event = [DomainEventTypes::SETTLEMENT_VOIDED, ['reason' => $reason]];
                break;
        }

        DB::transaction(function () use ($s, $action, $before, $next, $changes, $event, $startFlow, $userId, $reason, $input): void {
            $updated = DB::table('settlements')->where('id', $s->id)->where('status', $before)
                ->update(['status' => $next] + $changes + ['updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
            if ($updated === 0) {
                throw new AyniException('stale_version', 409, (string) trans('payroll.settlements.stale'));
            }
            if ($action === 'void') {
                $flow = $this->runningApproval((string) $s->id);
                if ($flow !== null) {
                    $this->approvals->cancel($flow, $reason);
                }
            }
            if ($startFlow && $userId !== null) {
                try {
                    $this->approvals->start(self::SUBJECT_TYPE, (string) $s->id, $userId, ['employment_id' => (string) $s->employment_id]);
                } catch (BusinessRuleViolatedException) {
                    // Ningún flujo aplica a esta liquidación: se aprueba directamente.
                }
            }
            $documentIds = [];
            if ($action === 'mark-paid') {
                // RN-LIQ-08 · Pagada: se emiten la hoja de liquidación, el certificado de trabajo, la carta de liberación de CTS y la constancia de cese.
                $documentIds = $this->documents->generate((string) $s->id, $userId);
            }
            if ($action === 'deliver-documents') {
                $documentIds = $this->documents->deliver((string) $s->id, (string) ($input['delivery_method'] ?? 'portal'));
                $event = [DomainEventTypes::SETTLEMENT_DOCUMENTS_ISSUED, ['document_ids' => $documentIds]];
            }
            $this->audit->record('settlement.'.str_replace('-', '_', $action), 'settlement', (string) $s->id, ['status' => $before],
                ['status' => $next] + array_intersect_key($changes, array_flip(['paid_on', 'payment_reference'])) + ($documentIds === [] ? [] : ['document_ids' => $documentIds]), $reason);
            if ($event !== null) {
                $this->outbox->record($event[0], 'settlement', (string) $s->id, ['settlement_id' => (string) $s->id, 'employment_id' => (string) $s->employment_id] + $event[1]);
            }
        });
    }

    /** WFL-2 · El flujo aprobó: aprueba quien decidió (con la segregación de funciones). Idempotente. */
    public function approvedByFlow(string $id, ?string $deciderUserId): void
    {
        $s = DB::table('settlements')->where('id', $id)->first();
        if ($s !== null && $s->status === 'pending_approval') {
            $this->transition($s, 'approve', $deciderUserId, [], true);
        }
    }

    /** WFL-2 · El flujo devolvió o se canceló: vuelve a «calculada» con el motivo. */
    public function rejectedByFlow(string $id, ?string $reason): void
    {
        $s = DB::table('settlements')->where('id', $id)->first();
        if ($s !== null && $s->status === 'pending_approval') {
            $this->transition($s, 'return', null, ['reason' => $reason ?? (string) trans('payroll.settlements.flow_rejected')], true);
        }
    }

    /** Cese revertido (COL-11): la liquidación abierta del cese se anula (idempotente). */
    public function voidForTermination(string $terminationId): void
    {
        foreach (DB::table('settlements')->where('termination_id', $terminationId)->whereIn('status', ['draft', 'calculated', 'pending_approval'])->get() as $s) {
            $this->transition($s, 'void', null, ['reason' => (string) trans('payroll.settlements.termination_cancelled')], true);
        }
    }

    /**
     * RN-LIQ-02 · Aviso de vencimiento: liquidaciones sin pagar cuyo plazo vence dentro de la anticipación o ya venció.
     * Avisa una sola vez por cálculo (due_alerted_at).
     */
    public function alertDue(): int
    {
        $count = 0;
        $limit = CarbonImmutable::now()->addHours(self::ALERT_LEAD_HOURS)->toDateString();
        foreach (DB::table('settlements')->whereIn('status', self::OPEN)->whereNull('deleted_at')->whereNull('due_alerted_at')
            ->whereNotNull('due_on')->where('due_on', '<=', $limit)->get() as $s) {
            $deadline = json_decode((string) ($s->periods ?? '{}'), true)['payment_deadline'] ?? null;
            $dueAt = is_array($deadline) ? (string) $deadline['due_at'] : substr((string) $s->due_on, 0, 10);
            if (CarbonImmutable::parse($dueAt)->subHours(self::ALERT_LEAD_HOURS)->isFuture()) {
                continue;
            }
            DB::transaction(function () use ($s, $dueAt): void {
                DB::table('settlements')->where('id', $s->id)->update(['due_alerted_at' => now()]);
                $this->outbox->record(DomainEventTypes::SETTLEMENT_PAYMENT_DUE, 'settlement', (string) $s->id, [
                    'settlement_id' => (string) $s->id, 'employment_id' => (string) $s->employment_id, 'due_at' => $dueAt, 'status' => (string) $s->status]);
            });
            $count++;
        }

        return $count;
    }

    /** Estado del plazo de pago para la lista y el detalle. */
    public static function dueStatus(\stdClass $s): string
    {
        $deadline = json_decode((string) ($s->periods ?? '{}'), true)['payment_deadline'] ?? null;
        if (! is_array($deadline)) {
            return 'no_deadline';
        }
        if ($s->paid_on !== null) {
            return substr((string) $s->paid_on, 0, 10) <= (string) $deadline['due_on'] ? 'paid_on_time' : 'paid_late';
        }
        $due = CarbonImmutable::parse((string) $deadline['due_at']);
        if ($due->isPast()) {
            return 'overdue';
        }

        return $due->subHours(self::ALERT_LEAD_HOURS)->isPast() ? 'due_soon' : 'on_time';
    }

    public function runningApproval(string $id): ?string
    {
        $v = DB::table('workflow_instances')->where('subject_type', self::SUBJECT_TYPE)->where('subject_id', $id)
            ->whereIn('status', ['pending', 'running', 'escalated'])->orderByDesc('started_at')->value('id');

        return $v === null ? null : (string) $v;
    }

    private function hasFlow(): bool
    {
        return DB::table('workflow_definitions')->where('kind', 'approval')->where('subject_type', self::SUBJECT_TYPE)
            ->where('status', 'active')->whereNotNull('current_version_id')->whereNull('deleted_at')->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(\stdClass $s, bool $withAmounts, bool $detail = false): array
    {
        $who = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $s->employment_id)
            ->first(['e.employee_code', 'e.legal_entity_id', DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as name")]);
        $reason = $s->reason_code === null ? null : DB::table('termination_reasons')->where('code', $s->reason_code)->value('name');
        $periods = json_decode((string) ($s->periods ?? 'null'), true);
        $deadline = is_array($periods) ? ($periods['payment_deadline'] ?? null) : null;
        $out = [
            'id' => (string) $s->id, 'employment_id' => (string) $s->employment_id, 'employee_name' => $who->name ?? null,
            'employee_code' => $who->employee_code ?? null, 'legal_entity_id' => $who->legal_entity_id ?? null,
            'termination_id' => $s->termination_id, 'termination_date' => substr((string) $s->termination_date, 0, 10),
            'reason_code' => $s->reason_code, 'reason_name' => $reason, 'status' => (string) $s->status,
            'severance_applies' => (bool) $s->severance_applies, 'include_pending_salary' => (bool) $s->include_pending_salary,
            'due_on' => $s->due_on === null ? null : substr((string) $s->due_on, 0, 10),
            'due_at' => is_array($deadline) ? $deadline['due_at'] : null, 'due_status' => self::dueStatus($s),
            'paid_on' => $s->paid_on === null ? null : substr((string) $s->paid_on, 0, 10), 'payment_reference' => $s->payment_reference,
            'calculated_at' => self::ts($s->calculated_at), 'calculated_by_user_id' => $s->calculated_by_user_id,
            'submitted_at' => self::ts($s->submitted_at), 'approved_at' => self::ts($s->approved_at), 'approved_by_user_id' => $s->approved_by_user_id,
            'return_reason' => $s->return_reason, 'void_reason' => $s->void_reason,
            'errors' => json_decode((string) ($s->errors ?? '[]'), true) ?? [], 'warnings' => json_decode((string) ($s->warnings ?? '[]'), true) ?? [],
            'allowed_actions' => $this->machine->allowedActions((string) $s->status), 'version' => (int) ($s->lock_version ?? 1),
        ];
        if ($withAmounts) {
            $currency = (string) $s->currency;
            $out['total'] = $s->total_amount === null ? null : ['amount' => (string) $s->total_amount, 'currency' => $currency];
            if ($detail) {
                $out['deductions'] = $this->inputs($s)['deductions'];
                $out['periods'] = is_array($periods) ? $periods : null;
                $out['totals'] = json_decode((string) ($s->totals ?? 'null'), true);
                $out['lines'] = DB::table('settlement_lines')->where('settlement_id', $s->id)->where('calculation_number', (int) ($s->calculation_number ?? 0))
                    ->orderBy('created_at')->orderBy('id')->get()
                    ->map(fn ($l): array => ['id' => (string) $l->id, 'kind' => (string) $l->kind, 'concept_code' => $l->concept_code, 'concept_name' => $l->concept_name,
                        'concept_kind' => $l->concept_kind, 'quantity' => $l->quantity === null ? null : (string) BigDecimal::of((string) $l->quantity)->stripTrailingZeros(),
                        'amount' => ['amount' => (string) $l->amount, 'currency' => (string) $l->currency], 'explanation' => $l->explanation,
                        'trace' => json_decode((string) $l->trace, true)])->all();
            }
        }
        if ($detail) {
            $out['documents'] = $this->documents->list((string) $s->id);
            $flow = $this->runningApproval((string) $s->id);
            $out['approval'] = $flow === null ? null : $this->approvals->summary($flow);
        }

        // Los campos opcionales sin valor no viajan (el contrato no los declara anulables).
        return array_filter($out, fn ($v): bool => $v !== null);
    }

    private static function ts(mixed $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse((string) $v)->toIso8601String();
    }
}
