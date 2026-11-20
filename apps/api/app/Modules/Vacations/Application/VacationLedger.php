<?php

namespace App\Modules\Vacations\Application;

use App\Modules\Vacations\Domain\LedgerEntryType;
use App\Modules\Vacations\Infrastructure\Models\VacationAccrualPeriod;
use App\Modules\Vacations\Infrastructure\Models\VacationLedgerEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Time\InsufficientVacationBalance;
use App\Shared\Time\VacationBalances;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * VAC-2 · Saldo de vacaciones = suma del libro de movimientos (RN-VAC-02). Implementa el contrato
 * compartido App\Shared\Time\VacationBalances que usan las solicitudes (VAC-3).
 *
 * Columnas del saldo: generadas (devengo y saldo inicial), ajustadas, programadas (reservas de
 * solicitudes aprobadas), tomadas, vendidas y liquidadas; disponibles es la suma de todo. Cada valor se
 * explica por sus movimientos (GET …/ledger).
 */
final class VacationLedger implements VacationBalances
{
    public function __construct(
        private readonly VacationAccrual $accrual,
        private readonly EmploymentFacts $facts,
        private readonly VacationPolicyCatalog $policies,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    public function balance(string $employmentId, ?string $asOf = null): array
    {
        $today = now()->toDateString();
        $asOf ??= $today;
        $this->accrual->sync($employmentId, $today);

        $sums = $this->sumsByPeriod($employmentId, $asOf);
        $periods = [];
        $totals = self::emptyColumns();
        $accruing = null;
        $overdue = BigDecimal::zero();
        $pending = false;
        foreach (VacationAccrualPeriod::query()->where('employment_id', $employmentId)->where('period_start', '<=', $asOf)->orderBy('period_start')->get() as $p) {
            $cols = $sums[$p->id] ?? self::emptyColumns();
            $pending = $pending || $p->pending_validation;
            if ($p->status === 'overdue') {
                $overdue = $overdue->plus($cols['available']);
            }
            if ($p->status === 'accruing') {
                $accruing = ['period_id' => $p->id, 'period_start' => $p->period_start, 'period_end' => $p->period_end,
                    'entitled_days' => self::num($p->entitled_days), 'accrued_days' => self::num($p->accrued_days)];
            }
            $periods[] = $this->presentPeriod($p, $cols);
            $totals = self::add($totals, $cols);
        }
        // Movimientos sin periodo (saldo inicial importado, ajustes generales).
        $totals = self::add($totals, $sums[''] ?? self::emptyColumns());

        $policy = $this->policyFor($employmentId, $asOf);
        $advance = '0';
        if (($policy['rules']['allow_advance'] ?? false) === true && $accruing !== null) {
            // El adelanto llega hasta lo devengado en el año en curso, con el tope de la política.
            $cap = $policy['rules']['max_advance_days'] ?? null;
            $advance = $cap === null ? $accruing['accrued_days'] : (string) BigDecimal::min(BigDecimal::of($accruing['accrued_days']), BigDecimal::of((string) $cap));
        }

        return [
            'employment_id' => $employmentId,
            'as_of' => $asOf,
            'totals' => self::presentColumns($totals),
            'accruing' => $accruing,
            'advance_available' => self::num($advance),
            'overdue_days' => self::num((string) $overdue),
            'pending_validation' => $pending || $policy['pending_validation'],
            'periods' => $periods,
            'policy' => $policy,
        ];
    }

    public function policyFor(string $employmentId, string $asOf): array
    {
        $employment = $this->facts->get($employmentId);

        return $this->policies->resolve($this->facts->contextAt($employment, $asOf), $asOf);
    }

    public function reserve(string $employmentId, string $vacationRequestId, string $days, string $effectiveOn, ?string $userId, string $maxAdvanceDays = '0'): array
    {
        $requested = BigDecimal::of($days);
        if (! $requested->isPositive()) {
            throw new AyniException('validation_failed', 422, (string) trans('vacations.days_positive'), [['field' => 'days', 'rule' => 'positive', 'message' => (string) trans('vacations.days_positive')]]);
        }

        return DB::transaction(function () use ($employmentId, $vacationRequestId, $requested, $effectiveOn, $userId, $maxAdvanceDays): array {
            $this->accrual->sync($employmentId);
            DB::table('employments')->where('id', $employmentId)->lockForUpdate()->first(['id']);
            $existing = $this->openReservations($vacationRequestId);
            if ($existing !== []) {
                return array_map(fn (VacationLedgerEntry $e): string => $e->id, $existing);
            }

            $plan = [];
            $remaining = $requested;
            $available = BigDecimal::zero();
            $periods = VacationAccrualPeriod::query()->where('employment_id', $employmentId)->whereIn('status', ['earned', 'overdue'])->orderBy('period_start')->get();
            foreach ($periods as $p) {
                $free = $this->accrual->availableInPeriod($p->id);
                if (! $free->isPositive()) {
                    continue;
                }
                $available = $available->plus($free);
                if ($remaining->isPositive()) {
                    $take = BigDecimal::min($free, $remaining);
                    $plan[] = [$p->id, $take];
                    $remaining = $remaining->minus($take);
                }
            }
            if ($remaining->isPositive()) {
                $advance = BigDecimal::of($maxAdvanceDays);
                $current = VacationAccrualPeriod::query()->where('employment_id', $employmentId)->where('status', 'accruing')->orderByDesc('period_start')->first();
                if ($current === null || $remaining->isGreaterThan($advance)) {
                    throw new InsufficientVacationBalance(self::num((string) $requested), self::num((string) $available), self::num((string) $advance));
                }
                $plan[] = [$current->id, $remaining];
            }

            // La reserva descuenta desde que se registra, aunque el goce empiece después.
            $bookedOn = min($effectiveOn, now()->toDateString());
            $ids = [];
            foreach ($plan as [$periodId, $take]) {
                $entry = VacationLedgerEntry::query()->create([
                    'employment_id' => $employmentId, 'vacation_accrual_period_id' => $periodId,
                    'entry_type' => LedgerEntryType::SCHEDULED, 'days' => (string) $take->negated(), 'effective_on' => $bookedOn,
                    'vacation_request_id' => $vacationRequestId, 'created_by_user_id' => $userId,
                    'reason' => (string) trans('vacations.scheduled_reason'),
                    'idempotency_key' => 'scheduled:'.$vacationRequestId.':'.$periodId,
                ]);
                $ids[] = $entry->id;
            }

            return $ids;
        });
    }

    public function release(string $vacationRequestId, string $reason, ?string $userId): void
    {
        DB::transaction(function () use ($vacationRequestId, $reason, $userId): void {
            foreach ($this->openReservations($vacationRequestId) as $entry) {
                $this->accrual->insertEntry([
                    'employment_id' => $entry->employment_id, 'vacation_accrual_period_id' => $entry->vacation_accrual_period_id,
                    'entry_type' => LedgerEntryType::REVERSAL, 'days' => (string) BigDecimal::of($entry->days)->negated(),
                    'effective_on' => now()->toDateString(), 'vacation_request_id' => $vacationRequestId,
                    'reverses_entry_id' => $entry->id, 'reason' => $reason, 'created_by_user_id' => $userId,
                    'idempotency_key' => 'release:'.$entry->id,
                ]);
                $this->accrual->sync($entry->employment_id);
            }
        });
    }

    public function consume(string $vacationRequestId, string $effectiveOn, ?string $userId): void
    {
        DB::transaction(function () use ($vacationRequestId, $effectiveOn, $userId): void {
            foreach ($this->openReservations($vacationRequestId) as $entry) {
                $this->accrual->insertEntry([
                    'employment_id' => $entry->employment_id, 'vacation_accrual_period_id' => $entry->vacation_accrual_period_id,
                    'entry_type' => LedgerEntryType::REVERSAL, 'days' => (string) BigDecimal::of($entry->days)->negated(),
                    'effective_on' => $effectiveOn, 'vacation_request_id' => $vacationRequestId, 'reverses_entry_id' => $entry->id,
                    'reason' => (string) trans('vacations.consumed_reason'), 'created_by_user_id' => $userId,
                    'idempotency_key' => 'consume:'.$entry->id,
                ]);
                $this->accrual->insertEntry([
                    'employment_id' => $entry->employment_id, 'vacation_accrual_period_id' => $entry->vacation_accrual_period_id,
                    'entry_type' => LedgerEntryType::TAKEN, 'days' => $entry->days, 'effective_on' => $effectiveOn,
                    'vacation_request_id' => $vacationRequestId, 'reason' => (string) trans('vacations.taken_reason'),
                    'created_by_user_id' => $userId, 'idempotency_key' => 'taken:'.$entry->id,
                ]);
            }
        });
    }

    /**
     * VAC-6 · Venta de días firmada: descuenta del saldo ganado (del periodo indicado o del más antiguo) con
     * movimientos «sold». Idempotente por acuerdo. Devuelve los movimientos creados.
     *
     * @return list<string>
     */
    public function sell(string $employmentId, string $agreementId, string $days, string $effectiveOn, ?string $periodId, string $reason, ?string $userId): array
    {
        $requested = BigDecimal::of($days);

        return DB::transaction(function () use ($employmentId, $agreementId, $requested, $effectiveOn, $periodId, $reason, $userId): array {
            $this->accrual->sync($employmentId);
            DB::table('employments')->where('id', $employmentId)->lockForUpdate()->first(['id']);
            $existing = array_values(VacationLedgerEntry::query()->where('idempotency_key', 'like', 'sale:'.$agreementId.':%')->pluck('id')->map(fn ($id): string => (string) $id)->all());
            if ($existing !== []) {
                return $existing;
            }
            $periods = VacationAccrualPeriod::query()->where('employment_id', $employmentId)->whereIn('status', ['earned', 'overdue'])
                ->when($periodId !== null, fn ($q) => $q->whereKey($periodId))->orderBy('period_start')->get();
            $plan = [];
            $remaining = $requested;
            foreach ($periods as $p) {
                if (! $remaining->isPositive()) {
                    break;
                }
                $free = $this->accrual->availableInPeriod($p->id);
                if (! $free->isPositive()) {
                    continue;
                }
                $take = BigDecimal::min($free, $remaining);
                $plan[] = [$p->id, $take];
                $remaining = $remaining->minus($take);
            }
            if ($remaining->isPositive()) {
                throw new AyniException('insufficient_balance', 422, (string) trans('vacations.agreements.insufficient_balance', ['days' => (string) $requested->toScale(2)]));
            }
            $ids = [];
            foreach ($plan as [$pid, $take]) {
                $entry = VacationLedgerEntry::query()->create([
                    'employment_id' => $employmentId, 'vacation_accrual_period_id' => $pid, 'entry_type' => LedgerEntryType::SOLD,
                    'days' => (string) $take->negated()->toScale(2), 'effective_on' => $effectiveOn, 'reason' => $reason,
                    'created_by_user_id' => $userId, 'idempotency_key' => 'sale:'.$agreementId.':'.$pid,
                ]);
                $ids[] = (string) $entry->id;
            }

            return $ids;
        });
    }

    /**
     * RN-VAC-13 · Ajuste manual con motivo y sustento: queda como movimiento auditable y emite
     * leave.balance_adjusted. Sin periodo, el ajuste va al saldo general de la relación.
     */
    public function adjust(string $employmentId, ?string $periodId, string $days, string $reason, string $evidence, ?string $userId): VacationLedgerEntry
    {
        $value = BigDecimal::of($days);
        if ($value->isZero()) {
            throw new AyniException('validation_failed', 422, (string) trans('vacations.adjust_zero'), [['field' => 'days', 'rule' => 'not_zero', 'message' => (string) trans('vacations.adjust_zero')]]);
        }
        if ($periodId !== null && ! VacationAccrualPeriod::query()->whereKey($periodId)->where('employment_id', $employmentId)->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('vacations.period_not_found'), [['field' => 'period_id', 'rule' => 'exists', 'message' => (string) trans('vacations.period_not_found')]]);
        }

        return DB::transaction(function () use ($employmentId, $periodId, $value, $reason, $evidence, $userId): VacationLedgerEntry {
            $this->accrual->sync($employmentId);
            $entry = VacationLedgerEntry::query()->create([
                'employment_id' => $employmentId, 'vacation_accrual_period_id' => $periodId,
                'entry_type' => LedgerEntryType::ADJUSTMENT, 'days' => (string) $value->toScale(2), 'effective_on' => now()->toDateString(),
                'reason' => $reason, 'evidence' => $evidence, 'created_by_user_id' => $userId,
            ]);
            $this->audit->record('vacation_balance.adjusted', 'employment', $employmentId, null,
                ['entry_id' => $entry->id, 'period_id' => $periodId, 'days' => (string) $value->toScale(2), 'evidence' => $evidence], $reason);
            $this->outbox->record(DomainEventTypes::LEAVE_BALANCE_ADJUSTED, 'employment', $employmentId, [
                'employment_id' => $employmentId, 'entry_id' => $entry->id, 'period_id' => $periodId,
                'days' => (string) $value->toScale(2), 'reason' => $reason,
            ]);
            $this->accrual->sync($employmentId);

            return $entry;
        });
    }

    /**
     * Movimientos de la relación, del más reciente al más antiguo.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(string $employmentId, ?string $periodId = null): array
    {
        $query = DB::table('vacation_ledger_entries as e')
            ->leftJoin('vacation_ledger_entries as r', 'r.id', '=', 'e.reverses_entry_id')
            ->leftJoin('vacation_accrual_periods as p', 'p.id', '=', 'e.vacation_accrual_period_id')
            ->where('e.employment_id', $employmentId)
            ->orderByDesc('e.effective_on')->orderByDesc('e.created_at');
        if ($periodId !== null) {
            $query->where('e.vacation_accrual_period_id', $periodId);
        }

        return array_values($query->get(['e.id', 'e.entry_type', 'e.days', 'e.effective_on', 'e.vacation_accrual_period_id', 'p.period_start', 'p.period_end',
            'e.vacation_request_id', 'e.reverses_entry_id', 'r.entry_type as reverses_entry_type', 'e.reason', 'e.evidence', 'e.created_by_user_id', 'e.created_at'])
            ->map(fn ($r): array => [
                'id' => (string) $r->id,
                'entry_type' => (string) $r->entry_type,
                'column' => LedgerEntryType::COLUMNS[$r->entry_type === LedgerEntryType::REVERSAL ? (string) $r->reverses_entry_type : (string) $r->entry_type] ?? 'adjusted',
                'days' => self::num((string) $r->days),
                'effective_on' => substr((string) $r->effective_on, 0, 10),
                'period_id' => $r->vacation_accrual_period_id,
                'period_start' => $r->period_start === null ? null : substr((string) $r->period_start, 0, 10),
                'period_end' => $r->period_end === null ? null : substr((string) $r->period_end, 0, 10),
                'vacation_request_id' => $r->vacation_request_id,
                'reverses_entry_id' => $r->reverses_entry_id,
                'reason' => $r->reason,
                'evidence' => $r->evidence,
                'created_by_user_id' => $r->created_by_user_id,
                'created_at' => $r->created_at === null ? null : date(DATE_ATOM, (int) strtotime((string) $r->created_at)),
            ])->all());
    }

    /**
     * @return list<VacationLedgerEntry>
     */
    private function openReservations(string $vacationRequestId): array
    {
        /** @var list<VacationLedgerEntry> */
        return array_values(VacationLedgerEntry::query()->where('vacation_request_id', $vacationRequestId)->where('entry_type', LedgerEntryType::SCHEDULED)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('vacation_ledger_entries as r')->whereColumn('r.reverses_entry_id', 'vacation_ledger_entries.id'))
            ->orderBy('created_at')->get()->all());
    }

    /**
     * Sumas por periodo y columna a la fecha. Clave '' = movimientos sin periodo.
     *
     * @return array<string, array<string, BigDecimal>>
     */
    private function sumsByPeriod(string $employmentId, string $asOf): array
    {
        $rows = DB::table('vacation_ledger_entries as e')
            ->leftJoin('vacation_ledger_entries as r', 'r.id', '=', 'e.reverses_entry_id')
            ->where('e.employment_id', $employmentId)->where('e.effective_on', '<=', $asOf)
            ->groupBy('e.vacation_accrual_period_id', 'e.entry_type', 'r.entry_type')
            ->get(['e.vacation_accrual_period_id as period_id', 'e.entry_type', 'r.entry_type as reversed_type', DB::raw('sum(e.days) as days')]);
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row->period_id ?? '');
            $out[$key] ??= self::emptyColumns();
            $type = $row->entry_type === LedgerEntryType::REVERSAL ? (string) $row->reversed_type : (string) $row->entry_type;
            $column = LedgerEntryType::COLUMNS[$type] ?? 'adjusted';
            $days = BigDecimal::of((string) $row->days);
            $out[$key][$column] = $out[$key][$column]->plus($days);
            $out[$key]['available'] = $out[$key]['available']->plus($days);
        }

        return $out;
    }

    /**
     * @param  array<string, BigDecimal>  $cols
     * @return array<string, mixed>
     */
    private function presentPeriod(VacationAccrualPeriod $p, array $cols): array
    {
        return [
            'id' => $p->id,
            'period_start' => $p->period_start,
            'period_end' => $p->period_end,
            'status' => $p->status,
            'entitled_days' => self::num($p->entitled_days),
            'accrued_days' => self::num($p->accrued_days),
            'earned_on' => $p->earned_on,
            'enjoy_by' => $p->enjoy_by,
            'pending_validation' => $p->pending_validation,
            'segments' => $p->entitlement_detail['segments'] ?? [],
        ] + self::presentColumns($cols);
    }

    /**
     * @return array<string, BigDecimal>
     */
    private static function emptyColumns(): array
    {
        $zero = BigDecimal::zero();

        return ['generated' => $zero, 'adjusted' => $zero, 'scheduled' => $zero, 'taken' => $zero, 'sold' => $zero, 'settled' => $zero, 'available' => $zero];
    }

    /**
     * @param  array<string, BigDecimal>  $a
     * @param  array<string, BigDecimal>  $b
     * @return array<string, BigDecimal>
     */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = $a[$k]->plus($v);
        }

        return $a;
    }

    /**
     * Generadas y ajustadas con su signo; programadas, tomadas, vendidas y liquidadas como días consumidos.
     *
     * @param  array<string, BigDecimal>  $cols
     * @return array{generated: string, adjusted: string, scheduled: string, taken: string, sold: string, settled: string, available: string}
     */
    private static function presentColumns(array $cols): array
    {
        return [
            'generated' => self::num((string) $cols['generated']),
            'adjusted' => self::num((string) $cols['adjusted']),
            'scheduled' => self::num((string) $cols['scheduled']->negated()),
            'taken' => self::num((string) $cols['taken']->negated()),
            'sold' => self::num((string) $cols['sold']->negated()),
            'settled' => self::num((string) $cols['settled']->negated()),
            'available' => self::num((string) $cols['available']),
        ];
    }

    private static function num(string $s): string
    {
        return (string) BigDecimal::of($s)->toScale(2);
    }

    public function onLeave(array $employmentIds, string $date): array
    {
        if ($employmentIds === []) {
            return [];
        }

        /** @var list<string> */
        return DB::table('vacation_requests')->whereIn('employment_id', $employmentIds)->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'in_progress'])->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
            ->distinct()->pluck('employment_id')->map(fn ($id) => (string) $id)->values()->all();
    }
}
