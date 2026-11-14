<?php

namespace App\Modules\Absences\Application;

use App\Modules\Absences\Domain\AbsenceRequestStateMachine;
use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceSubsidyCase;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Shared\Audit\AuditLogger;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUS-3 · RN-AUS-03 · Descansos médicos: acumulado del año y reparto entre lo que paga el empleador y lo que
 * pasa a subsidio.
 *
 * Los descansos de incapacidad (regla del tipo con subsidy_kind = illness) se ordenan por fecha de inicio dentro
 * del año calendario de su inicio; los primeros días hasta el parámetro legal descanso_medico_dias_cargo_empleador
 * los paga el empleador y los siguientes son subsidio. La maternidad (subsidy_kind = maternity) es subsidio
 * completo. Mientras el parámetro no tenga valor, todo queda a cargo del empleador y el resumen lo marca
 * «por validar» (nunca se asume el número).
 *
 * Al aprobarse un descanso con días de subsidio se abre su expediente (AbsenceSubsidyCase) para seguir el
 * reembolso y se emite absence.subsidy_started (aviso a quien gestiona subsidios). El reparto se recalcula
 * cuando una ausencia del año se envía, aprueba, rechaza o cancela; un expediente que aún no se presentó se
 * ajusta o se anula con él.
 *
 * Limitación conocida: un descanso que cruza el fin de año se cuenta entero en el año de su inicio.
 */
final class MedicalLeaveSplit
{
    public const THRESHOLD_PARAMETER = 'descanso_medico_dias_cargo_empleador';

    public const FILING_PARAMETER = 'subsidio_reembolso_plazo_meses';

    public function __construct(
        private readonly LegalParameters $legal,
        private readonly AbsenceTypeCatalog $types,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Umbral del año a cargo del empleador a una fecha.
     *
     * @return array{days: string|null, pending_validation: bool, legal_source: string|null}
     */
    public function threshold(string $asOf): array
    {
        $p = $this->legal->at($this->country(), self::THRESHOLD_PARAMETER, $asOf);
        $value = $p === null || ! is_string($p['value']) ? null : $p['value'];

        return ['days' => $value, 'pending_validation' => $p === null || $p['pending_validation'], 'legal_source' => $p['legal_source'] ?? null];
    }

    /**
     * Cómo quedaría una ausencia nueva o editada (simulación): días previos del año, a cargo del empleador y
     * de subsidio, y si con ella se alcanza el umbral.
     *
     * @return array{kind: string, year: int, threshold_days: string|null, pending_validation: bool, prior_days: string, accumulated_days: string, employer_paid_days: string, subsidized_days: string, reaches_threshold: bool}|null
     */
    public function preview(AbsenceType $type, string $employmentId, string $startsOn, string $days, ?string $ignoreId = null): ?array
    {
        $kind = $this->kind($type, $startsOn);
        if ($kind === null) {
            return null;
        }
        $year = (int) substr($startsOn, 0, 4);
        $threshold = $this->threshold($startsOn);
        $prior = '0';
        if ($kind === 'illness') {
            foreach ($this->yearRows($employmentId, $year) as $row) {
                if ($row['id'] === $ignoreId || $row['kind'] !== 'illness' || $row['starts_on'] > $startsOn) {
                    continue;
                }
                $prior = (string) BigDecimal::of($prior)->plus($row['days']);
            }
        }
        [$employer, $subsidy] = $this->split($kind, $threshold['days'], $prior, $days);
        $accumulated = $kind === 'illness' ? (string) BigDecimal::of($prior)->plus($days) : $days;
        $reaches = $kind === 'maternity' || ($threshold['days'] !== null && BigDecimal::of($accumulated)->isGreaterThanOrEqualTo($threshold['days']));

        return [
            'kind' => $kind, 'year' => $year, 'threshold_days' => $threshold['days'], 'pending_validation' => $threshold['pending_validation'],
            'prior_days' => self::number($prior), 'accumulated_days' => self::number($accumulated),
            'employer_paid_days' => self::number($employer), 'subsidized_days' => self::number($subsidy), 'reaches_threshold' => $reaches,
        ];
    }

    /**
     * Recalcula el reparto de todas las ausencias con subsidio de la relación en el año y sincroniza sus
     * expedientes. Idempotente.
     */
    public function recompute(string $employmentId, int $year): void
    {
        $rows = $this->yearRows($employmentId, $year);
        if ($rows === []) {
            $this->syncCases($employmentId, $year, []);

            return;
        }
        $threshold = $this->threshold($year.'-12-31');
        $prior = '0';
        $splits = [];
        foreach ($rows as $row) {
            [$employer, $subsidy] = $this->split($row['kind'], $threshold['days'], $prior, $row['days']);
            if ($row['kind'] === 'illness') {
                $prior = (string) BigDecimal::of($prior)->plus($row['days']);
            }
            DB::table('absence_requests')->where('id', $row['id'])->update(['employer_paid_days' => $employer, 'subsidized_days' => $subsidy]);
            $splits[$row['id']] = $row + ['employer_paid_days' => $employer, 'subsidized_days' => $subsidy];
        }
        $this->syncCases($employmentId, $year, $splits);
    }

    /**
     * Resumen del año de una relación: umbral, acumulado, días del empleador y de subsidio, y cada descanso.
     *
     * @return array<string, mixed>
     */
    public function summary(string $employmentId, int $year): array
    {
        $this->recompute($employmentId, $year);
        $threshold = $this->threshold($year.'-12-31');
        $items = [];
        $accumulated = BigDecimal::zero();
        $employer = BigDecimal::zero();
        $subsidy = BigDecimal::zero();
        foreach ($this->yearRows($employmentId, $year) as $row) {
            /** @var AbsenceRequest $a */
            $a = AbsenceRequest::query()->findOrFail($row['id']);
            $case = AbsenceSubsidyCase::query()->where('absence_request_id', $a->id)->first();
            if ($row['kind'] === 'illness') {
                $accumulated = $accumulated->plus($row['days']);
            }
            $employer = $employer->plus((string) ($a->employer_paid_days ?? '0'));
            $subsidy = $subsidy->plus((string) ($a->subsidized_days ?? '0'));
            $items[] = [
                'absence_request_id' => $a->id, 'kind' => $row['kind'], 'status' => $a->status,
                'starts_on' => $a->day('starts_on'), 'ends_on' => $a->day('ends_on'), 'days' => self::number($row['days']),
                'employer_paid_days' => self::number((string) ($a->employer_paid_days ?? '0')),
                'subsidized_days' => self::number((string) ($a->subsidized_days ?? '0')),
                'subsidy_case_id' => $case?->id, 'subsidy_status' => $case?->status,
            ];
        }
        $remaining = $threshold['days'] === null ? null : BigDecimal::of($threshold['days'])->minus($accumulated);

        return [
            'employment_id' => $employmentId,
            'year' => $year,
            'threshold_days' => $threshold['days'],
            'pending_validation' => $threshold['pending_validation'],
            'legal_source' => $threshold['legal_source'],
            'accumulated_days' => self::number((string) $accumulated),
            'employer_paid_days' => self::number((string) $employer),
            'subsidized_days' => self::number((string) $subsidy),
            'remaining_employer_days' => $remaining === null ? null : self::number((string) ($remaining->isNegative() ? BigDecimal::zero() : $remaining)),
            'threshold_reached' => $threshold['days'] !== null && $accumulated->isGreaterThanOrEqualTo($threshold['days']),
            'items' => $items,
        ];
    }

    /** Tipo de subsidio de la regla vigente del tipo al inicio, o null si no genera subsidio. */
    public function kind(AbsenceType $type, string $asOf): ?string
    {
        $rule = $this->types->rulesAt($type, $asOf);
        $kind = $rule?->subsidy_kind;

        return in_array($kind, ['illness', 'maternity'], true) ? $kind : null;
    }

    // ------------------------------------------------------------------ Internos

    /**
     * Ausencias del año (por fecha de inicio) que generan subsidio y ocupan el calendario, en orden.
     *
     * @return list<array{id: string, kind: string, status: string, starts_on: string, ends_on: string, days: string}>
     */
    private function yearRows(string $employmentId, int $year): array
    {
        $rows = AbsenceRequest::query()->where('employment_id', $employmentId)
            ->whereIn('status', AbsenceRequestStateMachine::ACTIVE_STATES)
            ->whereBetween('starts_on', [$year.'-01-01', $year.'-12-31'])
            ->orderBy('starts_on')->orderBy('created_at')->get();
        $types = [];
        $out = [];
        foreach ($rows as $a) {
            $type = $types[$a->absence_type_id] ??= AbsenceType::query()->withTrashed()->find($a->absence_type_id);
            if (! $type instanceof AbsenceType) {
                continue;
            }
            $kind = $this->kind($type, $a->day('starts_on'));
            if ($kind === null) {
                continue;
            }
            $out[] = [
                'id' => $a->id, 'kind' => $kind, 'status' => $a->status, 'starts_on' => $a->day('starts_on'),
                'ends_on' => $a->day('ends_on'), 'days' => (string) BigDecimal::of((string) ($a->calendar_days ?? $a->days ?? '0')),
            ];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string} días del empleador y de subsidio
     */
    private function split(string $kind, ?string $threshold, string $prior, string $days): array
    {
        if ($kind === 'maternity') {
            return ['0', self::number($days)];
        }
        if ($threshold === null) {
            return [self::number($days), '0'];
        }
        $left = BigDecimal::of($threshold)->minus($prior);
        if ($left->isNegativeOrZero()) {
            return ['0', self::number($days)];
        }
        $employer = $left->isGreaterThanOrEqualTo($days) ? BigDecimal::of($days) : $left;

        return [self::number((string) $employer), self::number((string) BigDecimal::of($days)->minus($employer))];
    }

    /**
     * Abre, ajusta o anula los expedientes del año según el reparto vigente. Un expediente ya presentado no se
     * toca: su seguimiento lo lleva quien gestiona subsidios.
     *
     * @param  array<string, array{id: string, kind: string, status: string, starts_on: string, ends_on: string, days: string, employer_paid_days: string, subsidized_days: string}>  $splits
     */
    private function syncCases(string $employmentId, int $year, array $splits): void
    {
        foreach ($splits as $id => $row) {
            $case = AbsenceSubsidyCase::query()->where('absence_request_id', $id)->first();
            $approved = in_array($row['status'], AbsenceRequestStateMachine::APPROVED_STATES, true);
            $hasSubsidy = BigDecimal::of($row['subsidized_days'])->isPositive();
            if ($approved && $hasSubsidy) {
                $from = CarbonImmutable::parse($row['starts_on'])->addDays((int) BigDecimal::of($row['employer_paid_days'])->toScale(0, RoundingMode::FLOOR)->toInt())->toDateString();
                if (! $case instanceof AbsenceSubsidyCase) {
                    $case = new AbsenceSubsidyCase([
                        'absence_request_id' => $id, 'employment_id' => $employmentId, 'kind' => $row['kind'], 'status' => 'pending_filing',
                        'subsidized_days' => $row['subsidized_days'], 'starts_on' => $from, 'ends_on' => $row['ends_on'], 'year' => $year,
                        'filing_due_on' => $this->filingDue($row['ends_on']), 'currency' => $this->currency(),
                    ]);
                    $case->save();
                    $this->audit->record('absence_subsidy.opened', 'absence_subsidy_case', $case->id, null, ['absence_request_id' => $id, 'kind' => $row['kind'], 'subsidized_days' => $row['subsidized_days']]);
                    $this->outbox->record(DomainEventTypes::ABSENCE_SUBSIDY_STARTED, 'absence_subsidy_case', $case->id, [
                        'subsidy_case_id' => $case->id, 'absence_request_id' => $id, 'employment_id' => $employmentId, 'kind' => $row['kind'],
                        'subsidized_days' => $row['subsidized_days'], 'starts_on' => $from, 'ends_on' => $row['ends_on'],
                        'tenant_id' => TenancyScope::requireTenant(),
                    ]);
                } elseif (in_array($case->status, ['pending_filing', 'cancelled'], true)) {
                    $case->forceFill(['status' => 'pending_filing', 'subsidized_days' => $row['subsidized_days'], 'starts_on' => $from, 'ends_on' => $row['ends_on'],
                        'filing_due_on' => $this->filingDue($row['ends_on'])]);
                    if ($case->isDirty()) {
                        $case->save();
                    }
                }

                continue;
            }
            if ($case instanceof AbsenceSubsidyCase && $case->status === 'pending_filing') {
                $case->forceFill(['status' => 'cancelled'])->save();
                $this->audit->record('absence_subsidy.cancelled', 'absence_subsidy_case', $case->id, null, ['absence_request_id' => $id]);
            }
        }

        // Ausencias del año que ya no ocupan el calendario (canceladas, rechazadas): su expediente sin presentar se anula.
        $orphans = AbsenceSubsidyCase::query()->where('employment_id', $employmentId)->where('year', $year)->where('status', 'pending_filing')
            ->whereNotIn('absence_request_id', array_keys($splits) === [] ? ['00000000-0000-0000-0000-000000000000'] : array_keys($splits))->get();
        foreach ($orphans as $case) {
            $case->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record('absence_subsidy.cancelled', 'absence_subsidy_case', $case->id, null, ['absence_request_id' => $case->absence_request_id]);
        }
    }

    private function filingDue(string $endsOn): ?string
    {
        $p = $this->legal->at($this->country(), self::FILING_PARAMETER, $endsOn);
        if ($p === null || ! is_string($p['value'])) {
            return null;
        }

        return CarbonImmutable::parse($endsOn)->addMonthsNoOverflow((int) BigDecimal::of($p['value'])->toScale(0, RoundingMode::FLOOR)->toInt())->toDateString();
    }

    private function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? '');
    }

    private function currency(): ?string
    {
        $c = DB::table('current_tenant')->value('default_currency');

        return is_string($c) && $c !== '' ? $c : null;
    }

    private static function number(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
