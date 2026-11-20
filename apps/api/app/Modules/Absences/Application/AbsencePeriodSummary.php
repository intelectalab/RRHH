<?php

namespace App\Modules\Absences\Application;

use App\Modules\Absences\Domain\AbsenceRequestStateMachine;
use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Shared\Labor\MasterData;
use App\Shared\Labor\RemoteWorkAgreements;
use App\Shared\Time\AbsenceIncidents;
use App\Shared\Time\WorkingDays;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUS-6 · Resumen de ausencias de un trabajador para un periodo, que la planilla consume como incidencias.
 *
 * Cada fecha queda explicada: fuera de la relación laboral, descanso semanal, feriado, laborado, laborado en
 * remoto (AUS-5: por acuerdo de teletrabajo total o por «trabajo remoto» aprobado; no es falta ni descuenta,
 * RN-AUS-09) o ausencia, con su efecto remunerativo (pagada, sin goce, a cargo del empleador o subsidio). Un
 * permiso por horas deja el día como laborado con sus horas de permiso.
 *
 * Sin el módulo de asistencia (todavía no existe), los días laborados salen del calendario laboral (descanso
 * semanal de la empresa y feriados de la sede), las ausencias aprobadas y las incidencias importadas del periodo.
 * El resumen no depende del idioma ni de la hora de consulta: la misma entrada da la misma huella.
 */
final class AbsencePeriodSummary implements AbsenceIncidents
{
    public function __construct(
        private readonly AbsenceTypeCatalog $types,
        private readonly WorkingDays $workingDays,
        private readonly MasterData $masterData,
        private readonly RemoteWorkAgreements $remoteWork,
    ) {}

    public function summary(string $employmentId, string $from, string $to): array
    {
        $employment = DB::table('employments')->where('id', $employmentId)->first(['id', 'legal_entity_id', 'hire_date', 'termination_date']);
        if ($employment === null) {
            throw new \InvalidArgumentException('employment_unknown');
        }
        $hire = substr((string) $employment->hire_date, 0, 10);
        $termination = $employment->termination_date === null ? null : substr((string) $employment->termination_date, 0, 10);
        $legalEntityId = (string) $employment->legal_entity_id;

        // La sede (feriados) es la de la asignación al cierre del periodo dentro de la relación laboral.
        $siteAsOf = min($to, $termination ?? $to);
        $siteAsOf = max($siteAsOf, $hire);
        $site = $this->masterData->at($employmentId, $siteAsOf)['site_id'] ?? null;
        $holidays = $this->workingDays->holidays($site, $from, $to);
        $restDays = $this->workingDays->restDays($legalEntityId);

        $coverage = $this->coverage($employmentId, $from, $to);
        $days = [];
        $types = [];
        $totals = ['calendar_days' => 0, 'employment_days' => 0, 'scheduled_days' => 0, 'worked_days' => 0, 'remote_days' => 0,
            'absence_days' => 0, 'rest_days' => 0, 'holidays' => 0, 'absence_hours' => '0'];

        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $date = $d->toDateString();
            $totals['calendar_days']++;
            $row = ['date' => $date, 'weekday' => WorkingDays::weekday($d), 'kind' => 'worked', 'remote_source' => null, 'absence' => null, 'hours_absent' => null];
            if ($date < $hire || ($termination !== null && $date > $termination)) {
                $row['kind'] = 'outside_employment';
                $days[] = $row;

                continue;
            }
            $totals['employment_days']++;
            $isRest = in_array($row['weekday'], $restDays, true);
            $isHoliday = in_array($date, $holidays, true);
            $scheduled = ! $isRest && ! $isHoliday;
            $absence = $coverage['days'][$date] ?? null;
            $remote = $coverage['remote'][$date] ?? null;

            if ($absence !== null && $absence['unit'] === 'days' && ($scheduled || $absence['day_count'] === 'calendar')) {
                $row['kind'] = 'absence';
                $row['absence'] = $absence;
                $totals['absence_days']++;
                $this->addType($types, $absence, 1, null);
            } elseif (! $scheduled) {
                $row['kind'] = $isHoliday ? 'holiday' : 'rest_day';
            } else {
                $agreement = $this->remoteWork->activeOn($employmentId, $date);
                if ($remote !== null) {
                    $row['kind'] = 'remote';
                    $row['remote_source'] = 'absence';
                } elseif ($agreement !== null && $agreement['modality'] === 'total') {
                    $row['kind'] = 'remote';
                    $row['remote_source'] = 'agreement';
                }
                $hourly = $coverage['hours'][$date] ?? null;
                if ($hourly !== null) {
                    $row['absence'] = $hourly;
                    $row['hours_absent'] = $hourly['hours'];
                    $totals['absence_hours'] = (string) BigDecimal::of($totals['absence_hours'])->plus($hourly['hours']);
                    $this->addType($types, $hourly, 0, $hourly['hours']);
                }
            }

            if ($scheduled) {
                $totals['scheduled_days']++;
            }
            match ($row['kind']) {
                'worked' => $totals['worked_days']++,
                'remote' => [$totals['worked_days']++, $totals['remote_days']++],
                'holiday' => $totals['holidays']++,
                'rest_day' => $totals['rest_days']++,
                default => null,
            };
            $days[] = $row;
        }

        ksort($types);
        $summary = [
            'employment_id' => $employmentId,
            'from' => $from,
            'to' => $to,
            'source' => 'calendar',
            'days' => $days,
            'types' => array_values($types),
            'totals' => $totals,
            'imported_incidents' => $this->importedIncidents($employmentId, $from, $to),
        ];
        $summary['fingerprint'] = self::fingerprint($summary);

        return $summary;
    }

    public function differences(string $employmentId, string $from, string $to): ?array
    {
        $closing = DB::table('absence_period_closings')->where('employment_id', $employmentId)
            ->where('period_from', $from)->where('period_to', $to)->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($closing === null) {
            return null;
        }
        /** @var array{days?: list<array<string, mixed>>, types?: list<array<string, mixed>>} $closed */
        $closed = json_decode((string) $closing->summary, true) ?: [];
        $current = $this->summary($employmentId, $from, $to);
        $changedDays = [];
        $before = [];
        foreach ($closed['days'] ?? [] as $d) {
            $before[(string) $d['date']] = $d;
        }
        foreach ($current['days'] as $d) {
            $old = $before[$d['date']] ?? null;
            if ($old === null || self::dayKey($old) !== self::dayKey($d)) {
                $changedDays[] = ['date' => $d['date'], 'before' => $old === null ? null : self::dayView($old), 'after' => self::dayView($d)];
            }
        }
        $typeDiff = [];
        $oldTypes = [];
        foreach ($closed['types'] ?? [] as $t) {
            $oldTypes[(string) $t['type_code']] = $t;
        }
        $newTypes = [];
        foreach ($current['types'] as $t) {
            $newTypes[(string) $t['type_code']] = $t;
        }
        foreach (array_unique(array_merge(array_keys($oldTypes), array_keys($newTypes))) as $code) {
            $o = $oldTypes[$code] ?? null;
            $n = $newTypes[$code] ?? null;
            $daysDelta = (int) ($n['days'] ?? 0) - (int) ($o['days'] ?? 0);
            $hoursDelta = (string) BigDecimal::of((string) ($n['hours'] ?? '0'))->minus((string) ($o['hours'] ?? '0'));
            if ($daysDelta !== 0 || ! BigDecimal::of($hoursDelta)->isZero()) {
                $typeDiff[] = ['type_code' => $code, 'days_delta' => $daysDelta, 'hours_delta' => $hoursDelta];
            }
        }

        return [
            'closed_at' => (string) $closing->created_at,
            'closed_fingerprint' => (string) $closing->fingerprint,
            'changed' => (string) $closing->fingerprint !== $current['fingerprint'],
            'days' => $changedDays,
            'types' => $typeDiff,
        ];
    }

    /**
     * Ausencias aprobadas del periodo, día por día: las de día completo, las de horas y el trabajo remoto.
     *
     * @return array{days: array<string, array<string, mixed>>, hours: array<string, array<string, mixed>>, remote: array<string, bool>}
     */
    private function coverage(string $employmentId, string $from, string $to): array
    {
        $out = ['days' => [], 'hours' => [], 'remote' => []];
        $rows = AbsenceRequest::query()->where('employment_id', $employmentId)
            ->whereIn('status', AbsenceRequestStateMachine::APPROVED_STATES)
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
            ->orderBy('starts_on')->orderBy('id')->get();
        $typeCache = [];
        foreach ($rows as $a) {
            $type = $typeCache[$a->absence_type_id] ??= AbsenceType::query()->withTrashed()->find($a->absence_type_id);
            if (! $type instanceof AbsenceType) {
                continue;
            }
            $startsOn = $a->day('starts_on');
            $endsOn = $a->day('ends_on');
            $rule = $this->types->rulesAt($type, $startsOn);
            $isPaid = (bool) ($rule->is_paid ?? true);
            $subsidyKind = (string) ($rule->subsidy_kind ?? 'none');
            $employerDays = (int) BigDecimal::of((string) ($a->employer_paid_days ?? '0'))->toScale(0, RoundingMode::DOWN)->toInt();
            $base = [
                'id' => (string) $a->id,
                'type_code' => (string) $type->code,
                'type_name' => (string) $type->name,
                'category' => (string) $type->category,
                'is_confidential' => (bool) $type->is_confidential,
                'unit' => (string) ($rule->unit ?? 'days'),
                'day_count' => (string) ($rule->day_count ?? 'calendar'),
                'payroll_concept_code' => $rule->payroll_concept_code ?? null,
                'statutory_suspension_code' => $rule->statutory_suspension_code ?? null,
                'counts_as_worked_for_benefits' => (bool) ($rule->counts_as_worked_for_benefits ?? true),
            ];
            if ($type->category === 'remote_work') {
                // RN-AUS-09: el trabajo remoto no es ausencia; el día queda como laborado en remoto.
                for ($d = CarbonImmutable::parse(max($startsOn, $from)); $d->toDateString() <= min($endsOn, $to); $d = $d->addDay()) {
                    $out['remote'][$d->toDateString()] = true;
                }

                continue;
            }
            if ($base['unit'] === 'hours') {
                $hours = (string) BigDecimal::of((string) ($a->hours ?? '0'));
                $out['hours'][$startsOn] = $base + ['effect' => $isPaid ? 'paid' : 'unpaid', 'hours' => $hours];

                continue;
            }
            $index = 0;
            for ($d = CarbonImmutable::parse($startsOn); $d->toDateString() <= $endsOn; $d = $d->addDay(), $index++) {
                $date = $d->toDateString();
                if ($date < $from || $date > $to) {
                    continue;
                }
                $effect = match (true) {
                    $subsidyKind === 'none' => $isPaid ? 'paid' : 'unpaid',
                    // Días calendario del descanso: los primeros a cargo del empleador, el resto subsidio (AUS-3).
                    $index < $employerDays => 'employer_paid',
                    default => 'subsidy',
                };
                $out['days'][$date] = $base + ['effect' => $effect, 'hours' => null];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $types
     * @param  array<string, mixed>  $absence
     */
    private function addType(array &$types, array $absence, int $days, ?string $hours): void
    {
        $code = (string) $absence['type_code'];
        $t = $types[$code] ?? [
            'type_code' => $code, 'type_name' => $absence['type_name'], 'category' => $absence['category'],
            'is_confidential' => $absence['is_confidential'], 'unit' => $absence['unit'],
            'payroll_concept_code' => $absence['payroll_concept_code'], 'statutory_suspension_code' => $absence['statutory_suspension_code'],
            'counts_as_worked_for_benefits' => $absence['counts_as_worked_for_benefits'],
            'days' => 0, 'hours' => '0', 'paid_days' => 0, 'unpaid_days' => 0, 'employer_paid_days' => 0, 'subsidized_days' => 0,
            'absence_ids' => [],
        ];
        $t['days'] += $days;
        if ($hours !== null) {
            $t['hours'] = (string) BigDecimal::of($t['hours'])->plus($hours);
        }
        if ($days > 0) {
            $key = match ($absence['effect']) {
                'unpaid' => 'unpaid_days',
                'employer_paid' => 'employer_paid_days',
                'subsidy' => 'subsidized_days',
                default => 'paid_days',
            };
            $t[$key] += $days;
        }
        if (! in_array($absence['id'], $t['absence_ids'], true)) {
            $t['absence_ids'][] = $absence['id'];
        }
        $types[$code] = $t;
    }

    /**
     * Incidencias importadas de los periodos de planilla que se cruzan con el resumen (sin asistencia, son la
     * otra fuente del periodo junto con el calendario y las ausencias aprobadas).
     *
     * @return list<array{id: string, concept_code: string, quantity: string|null, payroll_period_id: string, notes: string|null}>
     */
    private function importedIncidents(string $employmentId, string $from, string $to): array
    {
        return array_values(DB::table('payroll_incidents AS i')
            ->join('payroll_periods AS p', 'p.id', '=', 'i.payroll_period_id')
            ->join('payroll_concepts AS c', 'c.id', '=', 'i.payroll_concept_id')
            ->where('i.employment_id', $employmentId)->where('i.source', 'import')->whereNull('i.deleted_at')
            ->where('p.starts_on', '<=', $to)->where('p.ends_on', '>=', $from)
            ->orderBy('p.starts_on')->orderBy('c.code')->orderBy('i.id')
            ->get(['i.id', 'c.code', 'i.quantity', 'i.payroll_period_id', 'i.notes'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'concept_code' => (string) $r->code,
                'quantity' => $r->quantity === null ? null : (string) BigDecimal::of((string) $r->quantity)->stripTrailingZeros(),
                'payroll_period_id' => (string) $r->payroll_period_id, 'notes' => $r->notes === null ? null : (string) $r->notes,
            ])->all());
    }

    /** @param array<string, mixed> $summary */
    public static function fingerprint(array $summary): string
    {
        return hash('sha256', (string) json_encode([$summary['days'], $summary['types'], $summary['imported_incidents']]));
    }

    /** @param array<string, mixed> $day */
    private static function dayKey(array $day): string
    {
        return (string) json_encode(self::dayView($day));
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private static function dayView(array $day): array
    {
        /** @var array<string, mixed>|null $absence */
        $absence = $day['absence'] ?? null;

        return [
            'kind' => $day['kind'],
            'remote_source' => $day['remote_source'] ?? null,
            'type_code' => $absence['type_code'] ?? null,
            'effect' => $absence['effect'] ?? null,
            'hours_absent' => $day['hours_absent'] ?? null,
        ];
    }
}
