<?php

namespace App\Modules\Vacations\Application;

use App\Modules\Vacations\Domain\LedgerEntryType;
use App\Modules\Vacations\Domain\VacationYear;
use App\Modules\Vacations\Infrastructure\Models\VacationAccrualPeriod;
use App\Modules\Vacations\Infrastructure\Models\VacationLedgerEntry;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * VAC-2 · Devengo de vacaciones por año de servicio de cada relación laboral.
 *
 * - Abre los periodos desde la fecha ancla (paquete de país) hasta hoy o hasta el cese.
 * - Un periodo se gana al completarse: sus días (prorrateados por los tramos de régimen y política del
 *   año) entran al libro como un movimiento «accrual» con clave de idempotencia; nunca dos veces.
 * - El periodo en curso solo informa su devengo proporcional (para adelantos y truncas); no está en el libro.
 * - Estados: en devengo → ganado → vencido (RN-VAC-09, plazo de goce de los parámetros legales) o
 *   cerrado (sin días disponibles). Un periodo vuelve a «ganado» si recupera días (cancelación).
 * - RN-VAC-12: al cesar, los días pendientes se llevan a la liquidación y el saldo queda en cero.
 */
final class VacationAccrual
{
    public function __construct(
        private readonly EmploymentFacts $facts,
        private readonly VacationPolicyCatalog $policies,
        private readonly LegalParameters $legal,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * Pone al día los periodos de la relación a la fecha $today. Idempotente.
     */
    public function sync(string $employmentId, ?string $today = null): void
    {
        $today ??= now()->toDateString();
        DB::transaction(function () use ($employmentId, $today): void {
            DB::table('employments')->where('id', $employmentId)->lockForUpdate()->first(['id']);
            $employment = $this->facts->get($employmentId);
            if (in_array($employment['status'], ['pending', 'cancelled'], true)) {
                return;
            }
            $country = $this->policies->country();
            $anchor = $this->facts->anchor($employment, $country);
            $end = $employment['termination_date'] !== null && $employment['termination_date'] < $today ? $employment['termination_date'] : $today;
            if ($anchor > $end) {
                return;
            }
            foreach (VacationYear::periods($anchor, $end) as $p) {
                $this->syncPeriod($employment, $p['start'], $p['end'], $today, $country);
            }
            $this->refreshStatuses($employmentId, $today);
        });
    }

    /**
     * RN-VAC-12 · Cese: los días disponibles de cada periodo pasan a la liquidación (movimiento
     * «settlement») y los periodos se cierran. El devengo del periodo en curso queda como truncas.
     */
    public function settle(string $employmentId, string $terminationDate, ?string $userId): void
    {
        DB::transaction(function () use ($employmentId, $terminationDate, $userId): void {
            $this->sync($employmentId, max($terminationDate, now()->toDateString()));
            foreach (VacationAccrualPeriod::query()->where('employment_id', $employmentId)->orderBy('period_start')->get() as $period) {
                $available = $this->availableInPeriod($period->id);
                if (! $available->isZero()) {
                    $this->insertEntry([
                        'employment_id' => $employmentId, 'vacation_accrual_period_id' => $period->id,
                        'entry_type' => LedgerEntryType::SETTLEMENT, 'days' => (string) $available->negated(),
                        'effective_on' => $terminationDate, 'reason' => (string) trans('vacations.settlement_reason'),
                        'created_by_user_id' => $userId, 'idempotency_key' => 'settlement:'.$period->id,
                    ]);
                }
                if ($period->status !== 'closed') {
                    $period->status = 'closed';
                    $period->save();
                }
            }
        });
    }

    public function availableInPeriod(string $periodId): BigDecimal
    {
        return BigDecimal::of((string) (DB::table('vacation_ledger_entries')->where('vacation_accrual_period_id', $periodId)->sum('days') ?: '0'));
    }

    /**
     * @param  array{id: string, legal_entity_id: string, termination_date: string|null}  $employment
     */
    private function syncPeriod(array $employment, string $start, string $end, string $today, string $country): void
    {
        $period = VacationAccrualPeriod::query()->firstOrNew(['employment_id' => $employment['id'], 'period_start' => $start]);
        if ($period->exists && in_array($period->status, ['earned', 'overdue', 'closed'], true) && $period->earned_on !== null && ! $period->pending_validation) {
            return; // Ya ganado: sus días están en el libro y no se recalculan.
        }
        // Un periodo ganado mientras los días del régimen estaban «por validar» se recalcula cuando el parámetro se
        // valida (ADR 0021): su movimiento de devengo se crea una sola vez (clave accrual:periodo).
        $calc = $this->entitlement($employment, $start, $end, $country);
        // Un año completo se gana al día siguiente de su fin; si el cese cae justo en el último día, también.
        $completed = $end < $today && ($employment['termination_date'] === null || $end <= $employment['termination_date']);
        $cutoff = $employment['termination_date'] !== null ? min($today, $employment['termination_date']) : $today;

        $period->fill([
            'period_end' => $end,
            'entitled_days' => $calc['days'],
            'accrued_days' => $completed ? $calc['days'] : VacationYear::accrued($start, $end, $calc['days'], $cutoff),
            'vacation_policy_id' => $calc['policy_id'],
            'entitlement_detail' => ['segments' => $calc['segments']],
            'pending_validation' => $calc['pending'],
        ]);
        if (! $completed) {
            $period->status = $employment['termination_date'] !== null && $employment['termination_date'] <= $today ? 'closed' : 'accruing';
            $period->save();

            return;
        }

        $earnedOn = (new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
        $months = $this->legal->at($country, VacationPolicyCatalog::legalCodes($country)['enjoy_within_months'], $end);
        $period->status = 'earned';
        $period->earned_on = $earnedOn;
        $period->enjoy_by = $months === null || $months['value'] === null || is_array($months['value']) ? null : VacationYear::enjoyBy($end, (int) $months['value']);
        $period->save();
        if (BigDecimal::of($calc['days'])->isPositive()) {
            $created = $this->insertEntry([
                'employment_id' => $employment['id'], 'vacation_accrual_period_id' => $period->id,
                'entry_type' => LedgerEntryType::ACCRUAL, 'days' => $calc['days'], 'effective_on' => $earnedOn,
                'reason' => (string) trans('vacations.accrual_reason', ['from' => $start, 'to' => $end]),
                'idempotency_key' => 'accrual:'.$period->id,
            ]);
            if ($created) {
                $this->outbox->record(DomainEventTypes::LEAVE_PERIOD_EARNED, 'employment', $employment['id'], [
                    'employment_id' => $employment['id'], 'period_id' => $period->id, 'period_start' => $start,
                    'period_end' => $end, 'days' => $calc['days'], 'enjoy_by' => $period->enjoy_by,
                ]);
            }
        }
    }

    /**
     * Días del periodo por tramos: en cada tramo rige la política (o la ley) que corresponde a esa fecha.
     *
     * @param  array{id: string, legal_entity_id: string}  $employment
     * @return array{days: string, pending: bool, policy_id: string|null, segments: list<array<string, mixed>>}
     */
    private function entitlement(array $employment, string $start, string $end, string $country): array
    {
        $code = VacationPolicyCatalog::legalCodes($country)['days_per_year'];
        $points = $this->facts->breakpoints($employment['id'], $start, $end, $country, $code);
        $segments = [];
        $policyId = null;
        foreach ($points as $i => $from) {
            $to = isset($points[$i + 1]) ? (new \DateTimeImmutable($points[$i + 1]))->modify('-1 day')->format('Y-m-d') : $end;
            $context = $this->facts->contextAt($employment, $from);
            $resolved = $this->policies->resolve($context, $from);
            $last = $segments === [] ? null : $segments[count($segments) - 1];
            if ($last !== null && $last['days_per_year'] === $resolved['days_per_year'] && $last['policy_id'] === $resolved['policy_id']
                && $last['labor_regime_code'] === $context['labor_regime_code']) {
                $segments[count($segments) - 1]['to'] = $to; // Mismo valor: se une al tramo anterior.

                continue;
            }
            $segments[] = [
                'from' => $from, 'to' => $to, 'days_per_year' => $resolved['days_per_year'], 'source' => $resolved['source'],
                'policy_id' => $resolved['policy_id'], 'policy_name' => $resolved['policy_name'],
                'labor_regime_code' => $context['labor_regime_code'], 'pending_validation' => $resolved['pending_validation'],
            ];
            $policyId = $resolved['policy_id'] ?? $policyId;
        }
        $calc = VacationYear::entitlement($start, $end, array_map(fn (array $s): array => ['from' => $s['from'], 'to' => $s['to'], 'days_per_year' => $s['days_per_year']], $segments));

        return ['days' => $calc['days'], 'pending' => $calc['pending'], 'policy_id' => $policyId, 'segments' => $segments];
    }

    /**
     * Ganado / vencido / cerrado según los días que le quedan en el libro.
     */
    private function refreshStatuses(string $employmentId, string $today): void
    {
        $periods = VacationAccrualPeriod::query()->where('employment_id', $employmentId)->whereIn('status', ['earned', 'overdue', 'closed'])
            ->whereNotNull('earned_on')->get();
        foreach ($periods as $period) {
            $available = $this->availableInPeriod($period->id);
            $status = ! $available->isPositive() ? 'closed' : ($period->enjoy_by !== null && $period->enjoy_by < $today ? 'overdue' : 'earned');
            if ($status === $period->status) {
                continue;
            }
            $period->status = $status;
            $period->save();
            if ($status === 'overdue') {
                $this->outbox->record(DomainEventTypes::LEAVE_PERIOD_EXPIRING, 'employment', $employmentId, [
                    'employment_id' => $employmentId, 'period_id' => $period->id, 'enjoy_by' => $period->enjoy_by,
                    'available_days' => (string) $available,
                ]);
            }
        }
    }

    /**
     * Inserta un movimiento salvo que su clave de idempotencia ya exista.
     *
     * @param  array<string, mixed>  $values
     */
    public function insertEntry(array $values): bool
    {
        if (isset($values['idempotency_key']) && VacationLedgerEntry::query()->where('idempotency_key', $values['idempotency_key'])->exists()) {
            return false;
        }
        VacationLedgerEntry::query()->create($values);

        return true;
    }
}
