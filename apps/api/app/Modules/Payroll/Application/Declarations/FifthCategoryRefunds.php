<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Modules\Payroll\Domain\FifthCategoryRefundStateMachine;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DEC-11 · Devoluciones de excesos de retención de quinta (ME-49 · RN-PE-QTA-11 a 18).
 *
 * - El monto sale del libro de retenciones (PER-11): el ajuste final negativo del cese o la regularización negativa de
 *   diciembre; en la declaración del trabajador lo informa RRHH con su sustento. Quien lo calcula queda registrado.
 * - Devolver lo aprueba otra persona (RN-PE-QTA-18: 409 segregation_of_duties; la base también lo impide) y asienta
 *   la devolución en el libro como fila «refund» con monto negativo; el libro no se edita.
 * - La compensación puede ser parcial: el saldo se arrastra al periodo siguiente hasta compensarse por completo; si
 *   no alcanza, el saldo se solicita a la administración tributaria y se guarda la referencia del trámite.
 * - Cerrar exige saldo en cero o la solicitud a la autoridad registrada.
 */
final class FifthCategoryRefunds
{
    public const ORIGINS = ['termination', 'december_adjustment', 'worker_declaration'];

    public function __construct(
        private readonly FifthCategoryRefundStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly LegalParameters $legal,
    ) {}

    /**
     * @param  list<string>|null  $employments
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(?array $employments, array $filters): array
    {
        $q = DB::table('fifth_category_refunds')->orderByDesc('fiscal_year')->orderByDesc('created_at');
        if ($employments !== null) {
            $q->whereIn('employment_id', $employments);
        }
        foreach (['employment_id', 'fiscal_year', 'status', 'origin'] as $k) {
            if (($filters[$k] ?? null) !== null) {
                $q->where($k, $filters[$k]);
            }
        }

        return array_values($q->limit(200)->get()->map(fn ($r): array => $this->present($r))->all());
    }

    public function employmentOf(string $id): ?string
    {
        $e = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('fifth_category_refunds')->where('id', $id)->value('employment_id') : null;

        return $e === null ? null : (string) $e;
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        return $this->present($this->row($id));
    }

    /**
     * @param  array{employment_id: string, fiscal_year: int, origin: string, amount?: string|null, worker_declaration_document_id?: string|null}  $in
     * @return array<string, mixed>
     */
    public function create(array $in, ?string $userId): array
    {
        $amount = match ($in['origin']) {
            'termination' => $this->excess($in['employment_id'], $in['fiscal_year'], 'final_adjustment', null),
            'december_adjustment' => $this->excess($in['employment_id'], $in['fiscal_year'], 'correction', 12),
            default => isset($in['amount']) ? BigDecimal::of((string) $in['amount']) : null,
        };
        if ($amount === null || ! $amount->isPositive()) {
            throw new AyniException('no_refundable_excess', 409, (string) trans('payroll.fifth_refunds.no_excess'));
        }
        if (DB::table('fifth_category_refunds')->where('employment_id', $in['employment_id'])->where('fiscal_year', $in['fiscal_year'])
            ->where('origin', $in['origin'])->where('status', '<>', 'closed')->exists()) {
            throw new AyniException('refund_already_open', 409, (string) trans('payroll.fifth_refunds.already_open'));
        }

        return DB::transaction(function () use ($in, $amount, $userId): array {
            $id = (string) DB::table('fifth_category_refunds')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $in['employment_id'], 'fiscal_year' => $in['fiscal_year'],
                'origin' => $in['origin'], 'amount' => (string) $amount->toScale(2), 'currency' => 'PEN', 'compensated_amount' => '0',
                'worker_declaration_document_id' => $in['worker_declaration_document_id'] ?? null, 'status' => 'pending',
                'calculated_by_user_id' => $userId, 'compensations' => '[]',
            ]);
            $this->audit->record('fifth_category_refund.calculated', 'fifth_category_refund', $id, null,
                ['origin' => $in['origin'], 'amount' => (string) $amount->toScale(2), 'calculated_by_user_id' => $userId]);

            return $this->show($id);
        });
    }

    /** Exceso del libro: valor absoluto de la última fila negativa del tipo (y mes) indicado. */
    private function excess(string $employmentId, int $year, string $kind, ?int $month): ?BigDecimal
    {
        $q = DB::table('fifth_category_ledger')->where('employment_id', $employmentId)->where('fiscal_year', $year)->where('entry_kind', $kind)
            ->where('withholding_amount', '<', 0)->orderByDesc('period_month')->orderByDesc('sequence');
        if ($month !== null) {
            $q->where('period_month', $month);
        }
        $v = $q->value('withholding_amount');

        return $v === null ? null : BigDecimal::of((string) $v)->abs();
    }

    /** @return array<string, mixed> */
    public function refund(string $id, ?string $refundedOn, ?string $runId, ?string $userId): array
    {
        $r = $this->row($id);
        $next = $this->machine->getNextState((string) $r->status, 'refund');
        if ($userId === null || ($r->calculated_by_user_id !== null && (string) $r->calculated_by_user_id === $userId)) {
            throw new AyniException('segregation_of_duties', 409, (string) trans('payroll.fifth_refunds.segregation'));
        }
        $on = $refundedOn ?? CarbonImmutable::today()->toDateString();
        $year = (int) $r->fiscal_year;
        $month = CarbonImmutable::parse($on)->year === $year ? CarbonImmutable::parse($on)->month : 12;
        $source = DB::table('fifth_category_ledger')->where('employment_id', $r->employment_id)->where('fiscal_year', $year)
            ->orderByDesc('period_month')->orderByDesc('sequence')->first(['uit_value', 'parameters', 'engine_version']);
        $uit = $source?->uit_value;
        if ($uit === null) {
            $p = $this->legal->at('PE', 'uit', $on);
            $uit = $p !== null && is_numeric($p['value']) ? (string) $p['value'] : null;
        }
        if ($uit === null) {
            throw new AyniException('legal_parameter_missing', 409, (string) trans('payroll.fifth_refunds.uit_missing'));
        }

        return DB::transaction(function () use ($r, $id, $next, $on, $year, $month, $source, $uit, $runId, $userId): array {
            $sequence = (int) DB::table('fifth_category_ledger')->where('employment_id', $r->employment_id)->where('fiscal_year', $year)
                ->where('period_month', $month)->where('entry_kind', 'refund')->max('sequence') + 1;
            DB::table('fifth_category_ledger')->insert([
                'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $r->employment_id, 'fiscal_year' => $year, 'period_month' => $month,
                'sequence' => $sequence, 'entry_kind' => 'refund', 'payroll_run_id' => $runId,
                'withholding_amount' => (string) BigDecimal::of((string) $r->amount)->negated(), 'currency' => (string) $r->currency,
                'uit_value' => $uit, 'parameters' => $source->parameters ?? '[]',
                'trace' => json_encode(['refund_id' => $id, 'origin' => (string) $r->origin, 'refunded_on' => $on]),
                'engine_version' => $source->engine_version ?? 'refund', 'created_by_user_id' => $userId,
            ]);
            DB::table('fifth_category_refunds')->where('id', $id)->update([
                'status' => $next, 'refunded_on' => $on, 'payroll_run_id' => $runId, 'refunded_by_user_id' => $userId,
            ]);
            $this->audit->record('fifth_category_refund.refunded', 'fifth_category_refund', $id, ['status' => (string) $r->status],
                ['status' => $next, 'refunded_on' => $on, 'refunded_by_user_id' => $userId]);
            $this->outbox->record(DomainEventTypes::FIFTH_REFUND_PAID, 'fifth_category_refund', $id, [
                'refund_id' => $id, 'employment_id' => (string) $r->employment_id, 'fiscal_year' => $year, 'amount' => (string) $r->amount,
            ]);

            return $this->show($id);
        });
    }

    /**
     * Compensa todo o parte del monto devuelto contra las retenciones de un periodo tributario. Con saldo, la
     * devolución sigue «devuelta» y el saldo se arrastra; al llegar a cero pasa a «compensada».
     *
     * @return array<string, mixed>
     */
    public function compensate(string $id, string $amount, string $periodCode, ?string $userId): array
    {
        $r = $this->row($id);
        $this->machine->getNextState((string) $r->status, 'compensate');
        $add = BigDecimal::of($amount);
        $total = BigDecimal::of((string) $r->compensated_amount)->plus($add);
        if (! $add->isPositive() || $total->isGreaterThan((string) $r->amount)) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.fifth_refunds.over_compensation'),
                [['field' => 'amount', 'rule' => 'max', 'message' => (string) trans('payroll.fifth_refunds.over_compensation')]]);
        }
        $full = $total->isEqualTo((string) $r->amount);
        $next = $full ? 'compensated' : (string) $r->status;

        return DB::transaction(function () use ($r, $id, $add, $total, $periodCode, $full, $next, $userId): array {
            $list = json_decode((string) ($r->compensations ?? '[]'), true) ?: [];
            $list[] = ['amount' => (string) $add->toScale(2), 'period_code' => $periodCode, 'by_user_id' => $userId, 'at' => now()->toIso8601ZuluString()];
            DB::table('fifth_category_refunds')->where('id', $id)->update([
                'compensated_amount' => (string) $total->toScale(2), 'compensation_period_code' => $periodCode, 'compensations' => json_encode($list), 'status' => $next,
            ]);
            $this->audit->record('fifth_category_refund.compensated', 'fifth_category_refund', $id,
                ['status' => (string) $r->status, 'compensated_amount' => (string) $r->compensated_amount],
                ['status' => $next, 'compensated_amount' => (string) $total->toScale(2), 'period_code' => $periodCode, 'full' => $full]);
            $this->outbox->record(DomainEventTypes::FIFTH_REFUND_COMPENSATED, 'fifth_category_refund', $id, [
                'refund_id' => $id, 'compensated_amount' => (string) $total->toScale(2), 'compensation_period' => $periodCode,
            ]);

            return $this->show($id);
        });
    }

    /** @return array<string, mixed> */
    public function requestAuthority(string $id, string $reference, ?string $userId): array
    {
        $r = $this->row($id);
        $next = $this->machine->getNextState((string) $r->status, 'request_authority_refund');

        return DB::transaction(function () use ($r, $id, $next, $reference, $userId): array {
            DB::table('fifth_category_refunds')->where('id', $id)->update(['status' => $next, 'authority_refund_reference' => $reference]);
            $this->audit->record('fifth_category_refund.authority_requested', 'fifth_category_refund', $id, ['status' => (string) $r->status],
                ['status' => $next, 'authority_refund_reference' => $reference, 'by_user_id' => $userId]);
            $this->outbox->record(DomainEventTypes::FIFTH_REFUND_REQUESTED, 'fifth_category_refund', $id, ['refund_id' => $id, 'authority_refund_reference' => $reference]);

            return $this->show($id);
        });
    }

    /** @return array<string, mixed> */
    public function close(string $id, ?string $userId): array
    {
        $r = $this->row($id);
        $next = $this->machine->getNextState((string) $r->status, 'close');

        return DB::transaction(function () use ($r, $id, $next, $userId): array {
            DB::table('fifth_category_refunds')->where('id', $id)->update(['status' => $next]);
            $this->audit->record('fifth_category_refund.closed', 'fifth_category_refund', $id, ['status' => (string) $r->status], ['status' => $next, 'by_user_id' => $userId]);
            $this->outbox->record(DomainEventTypes::FIFTH_REFUND_CLOSED, 'fifth_category_refund', $id, ['refund_id' => $id]);

            return $this->show($id);
        });
    }

    private function row(string $id): \stdClass
    {
        $r = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('fifth_category_refunds')->where('id', $id)->first() : null;
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private function present(\stdClass $r): array
    {
        $cur = (string) $r->currency;
        $balance = BigDecimal::of((string) $r->amount)->minus((string) $r->compensated_amount);

        return [
            'id' => (string) $r->id, 'employment_id' => (string) $r->employment_id, 'fiscal_year' => (int) $r->fiscal_year, 'origin' => (string) $r->origin,
            'amount' => ['amount' => (string) $r->amount, 'currency' => $cur],
            'refunded_on' => $r->refunded_on === null ? null : substr((string) $r->refunded_on, 0, 10),
            'compensated_amount' => ['amount' => (string) $r->compensated_amount, 'currency' => $cur],
            'balance' => ['amount' => (string) $balance->toScale(2), 'currency' => $cur],
            'compensation_period' => $r->compensation_period_code === null ? null : (string) $r->compensation_period_code,
            'compensations' => json_decode((string) ($r->compensations ?? '[]'), true) ?: [],
            'authority_refund_reference' => $r->authority_refund_reference === null ? null : (string) $r->authority_refund_reference,
            'calculated_by_user_id' => $r->calculated_by_user_id === null ? null : (string) $r->calculated_by_user_id,
            'refunded_by_user_id' => $r->refunded_by_user_id === null ? null : (string) $r->refunded_by_user_id,
            'status' => (string) $r->status,
            'allowed_actions' => array_map(fn (string $a): string => str_replace('_', '-', $a), $this->machine->allowedActions((string) $r->status)),
            'created_at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601ZuluString(),
            'updated_at' => CarbonImmutable::parse((string) $r->updated_at)->toIso8601ZuluString(),
        ];
    }
}
