<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Infrastructure\Models\PayrollIncident;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Time\AbsenceIncidents;
use App\Shared\Time\VacationPayrollIncidents;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PER-3 · Consumo de tiempo por la planilla (C-11).
 *
 * Toma, por cada trabajador de la población y hasta la fecha de corte del periodo (attendance_from/to), el resumen
 * de ausencias del puerto AbsenceIncidents (AUS-6; sin módulo de asistencia sale del calendario, las ausencias
 * aprobadas y las incidencias importadas) y las ventas de vacaciones firmadas de VacationPayrollIncidents (VAC-6).
 * Cada tipo con concepto de planilla se vuelve una incidencia con origen del módulo (no editable, RN-PER-09); se
 * actualiza o retira si el origen cambió. El corte queda registrado (solo inserción) con su resumen y su huella
 * para reproducir el cálculo. Lo ocurrido después del corte del periodo anterior entra como diferencia
 * (incidencia «retroactive» que apunta a ese periodo).
 */
final class PayrollTimeConsumption
{
    private const ABSENCE_PREFIX = 'absence_type:';

    public function __construct(
        private readonly PayrollPeriods $periods,
        private readonly AbsenceIncidents $absences,
        private readonly VacationPayrollIncidents $vacations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function consume(PayrollPeriod $p, ?string $userId): array
    {
        if (! $this->periods->isEditable($p)) {
            throw new AyniException('period_locked', 409, (string) trans('payroll.incidents.period_locked'));
        }
        $from = $p->cutoffFrom();
        $to = $p->cutoffTo();
        $population = $this->periods->population($p);
        $previous = $this->periods->previous($p->payroll_group_id, $p->kind, (string) $p->day('starts_on'));
        $concepts = DB::table('payroll_concepts')->whereNull('deleted_at')->where('status', 'active')->pluck('id', 'code')
            ->map(fn ($id): string => (string) $id)->all();
        $stats = ['employments' => count($population), 'created' => 0, 'updated' => 0, 'removed' => 0, 'differences' => 0, 'cutoffs' => 0];
        $missing = [];

        DB::transaction(function () use ($p, $from, $to, $population, $previous, $concepts, $userId, &$stats, &$missing): void {
            foreach ($population as $employmentId) {
                $summary = $this->absences->summary($employmentId, $from, $to);
                $sales = $this->vacations->sales($employmentId, $from, $to);
                $desired = [];
                foreach ($summary['types'] ?? [] as $type) {
                    $code = $type['payroll_concept_code'] ?? null;
                    $qty = $type['unit'] === 'hours' ? (string) $type['hours'] : (string) $type['days'];
                    if (! is_string($code) || $code === '' || BigDecimal::of($qty)->isZero()) {
                        continue;
                    }
                    $desired['absence|'.self::ABSENCE_PREFIX.$type['type_code'].'|'] = ['source' => 'absence', 'source_type' => self::ABSENCE_PREFIX.$type['type_code'],
                        'source_id' => null, 'code' => $code, 'quantity' => $qty, 'retro' => null];
                }
                foreach ($sales as $sale) {
                    $desired['benefit|vacation_agreement|'.$sale['agreement_id']] = ['source' => 'benefit', 'source_type' => 'vacation_agreement',
                        'source_id' => $sale['agreement_id'], 'code' => $sale['concept_code'], 'quantity' => $sale['days'], 'retro' => null];
                }
                if ($previous !== null) {
                    foreach ($this->differencesSinceCutoff($previous, $employmentId) as $diff) {
                        $desired['retroactive|'.self::ABSENCE_PREFIX.$diff['type_code'].'|'.$previous->id] = ['source' => 'retroactive',
                            'source_type' => self::ABSENCE_PREFIX.$diff['type_code'], 'source_id' => null, 'code' => $diff['code'], 'quantity' => $diff['delta'], 'retro' => $previous->id];
                    }
                }
                $this->sync($p, $employmentId, $desired, $concepts, $stats, $missing);

                $fingerprint = hash('sha256', (string) $summary['fingerprint'].'|'.json_encode($sales));
                $last = DB::table('payroll_period_time_cutoffs')->where('payroll_period_id', $p->id)->where('employment_id', $employmentId)
                    ->orderByDesc('created_at')->orderByDesc('id')->value('fingerprint');
                if ($last !== $fingerprint) {
                    DB::table('payroll_period_time_cutoffs')->insert([
                        'tenant_id' => $p->tenant_id, 'payroll_period_id' => $p->id, 'employment_id' => $employmentId,
                        'period_from' => $from, 'period_to' => $to, 'fingerprint' => $fingerprint, 'consumed_by_user_id' => $userId,
                        'summary' => json_encode(['types' => $summary['types'] ?? [], 'totals' => $summary['totals'] ?? [], 'sales' => $sales,
                            'absence_fingerprint' => $summary['fingerprint'], 'source' => $summary['source'] ?? 'calendar']),
                    ]);
                    $stats['cutoffs']++;
                }
            }
            $p->fill(['time_consumed_at' => now(), 'time_consumed_by_user_id' => $userId])->save();
            $this->audit->record('payroll_period.time_consumed', 'payroll_period', $p->id, null, $stats + ['from' => $from, 'to' => $to]);
        });

        return $stats + [
            'from' => $from, 'to' => $to,
            'missing_concepts' => array_values(array_unique($missing)),
            'out_of_cutoff' => $to < (string) $p->day('ends_on') ? ['from' => CarbonImmutable::parse($to)->addDay()->toDateString(), 'to' => (string) $p->day('ends_on')] : null,
        ];
    }

    /**
     * Por trabajador: lo tomado al corte, si cambió desde entonces, lo que quedó fuera de corte y las diferencias
     * desde el corte del periodo anterior.
     *
     * @param  list<string>  $employments
     * @return list<array<string, mixed>>
     */
    public function summary(PayrollPeriod $p, array $employments): array
    {
        $from = $p->cutoffFrom();
        $to = $p->cutoffTo();
        $ends = (string) $p->day('ends_on');
        $previous = $this->periods->previous($p->payroll_group_id, $p->kind, (string) $p->day('starts_on'));
        $out = [];
        foreach ($employments as $employmentId) {
            $cutoff = DB::table('payroll_period_time_cutoffs')->where('payroll_period_id', $p->id)->where('employment_id', $employmentId)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            $stored = $cutoff === null ? null : json_decode((string) $cutoff->summary, true);
            $current = $this->absences->summary($employmentId, $from, $to);
            $sales = $this->vacations->sales($employmentId, $from, $to);
            $outside = null;
            if ($to < $ends) {
                $after = $this->absences->summary($employmentId, CarbonImmutable::parse($to)->addDay()->toDateString(), $ends);
                $outside = ['from' => CarbonImmutable::parse($to)->addDay()->toDateString(), 'to' => $ends,
                    'types' => array_values(array_filter($after['types'] ?? [], fn (array $t): bool => $t['days'] > 0 || ! BigDecimal::of((string) $t['hours'])->isZero()))];
            }
            $out[] = [
                'employment_id' => $employmentId,
                'cutoff' => $cutoff === null ? null : [
                    'consumed_at' => CarbonImmutable::parse((string) $cutoff->created_at)->toIso8601String(), 'from' => (string) $cutoff->period_from,
                    'to' => (string) $cutoff->period_to, 'fingerprint' => (string) $cutoff->fingerprint, 'types' => is_array($stored) ? ($stored['types'] ?? []) : [],
                ],
                'changed_since_cutoff' => $cutoff !== null && hash('sha256', (string) $current['fingerprint'].'|'.json_encode($sales)) !== (string) $cutoff->fingerprint,
                'current' => ['types' => $current['types'] ?? [], 'totals' => $current['totals'] ?? []],
                'out_of_cutoff' => $outside,
                'previous_period_differences' => $previous === null ? [] : $this->differencesSinceCutoff($previous, $employmentId),
            ];
        }

        return $out;
    }

    /**
     * Diferencias por tipo entre el último corte de un periodo y lo que hoy dice el puerto para ese mismo rango.
     *
     * @return list<array{type_code: string, code: string, delta: string}>
     */
    private function differencesSinceCutoff(PayrollPeriod $previous, string $employmentId): array
    {
        $cutoff = DB::table('payroll_period_time_cutoffs')->where('payroll_period_id', $previous->id)->where('employment_id', $employmentId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($cutoff === null) {
            return [];
        }
        $stored = json_decode((string) $cutoff->summary, true);
        $before = [];
        foreach (is_array($stored) ? ($stored['types'] ?? []) : [] as $t) {
            $before[$t['type_code']] = $t;
        }
        $now = $this->absences->summary($employmentId, (string) $cutoff->period_from, (string) $cutoff->period_to);
        $after = [];
        foreach ($now['types'] ?? [] as $t) {
            $after[$t['type_code']] = $t;
        }
        $diffs = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $code) {
            $t = $after[$code] ?? $before[$code];
            $concept = $t['payroll_concept_code'] ?? null;
            if (! is_string($concept) || $concept === '') {
                continue;
            }
            $qty = fn (?array $x): BigDecimal => $x === null ? BigDecimal::zero() : BigDecimal::of($x['unit'] === 'hours' ? (string) $x['hours'] : (string) $x['days']);
            $delta = $qty($after[$code] ?? null)->minus($qty($before[$code] ?? null));
            if (! $delta->isZero()) {
                $diffs[] = ['type_code' => (string) $code, 'code' => $concept, 'delta' => (string) $delta];
            }
        }

        return $diffs;
    }

    /**
     * Crea, actualiza o retira (papelera) las incidencias de origen de módulo del trabajador en el periodo.
     *
     * @param  array<string, array{source: string, source_type: string, source_id: ?string, code: string, quantity: string, retro: ?string}>  $desired
     * @param  array<string, string>  $concepts
     * @param  array<string, int>  $stats
     * @param  list<string>  $missing
     */
    private function sync(PayrollPeriod $p, string $employmentId, array $desired, array $concepts, array &$stats, array &$missing): void
    {
        $existing = PayrollIncident::query()->where('payroll_period_id', $p->id)->where('employment_id', $employmentId)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('source', ['absence', 'retroactive'])->where('source_type', 'like', self::ABSENCE_PREFIX.'%'))
                ->orWhere(fn ($w) => $w->where('source', 'benefit')->where('source_type', 'vacation_agreement')))
            ->get()->keyBy(fn (PayrollIncident $i): string => $i->source.'|'.$i->source_type.'|'.($i->source === 'retroactive' ? $i->retro_period_id : $i->source_id));
        foreach ($desired as $key => $d) {
            $conceptId = $concepts[$d['code']] ?? null;
            if ($conceptId === null) {
                $missing[] = $d['code'];

                continue;
            }
            $quantity = (string) BigDecimal::of($d['quantity'])->toScale(4);
            $current = $existing->pull($key);
            if ($current instanceof PayrollIncident) {
                if ($current->quantity === null || ! BigDecimal::of($current->quantity)->isEqualTo($quantity) || $current->payroll_concept_id !== $conceptId) {
                    $current->fill(['quantity' => $quantity, 'payroll_concept_id' => $conceptId, 'status' => 'approved'])->save();
                    $stats['updated']++;
                }

                continue;
            }
            PayrollIncident::query()->create([
                'payroll_period_id' => $p->id, 'employment_id' => $employmentId, 'payroll_concept_id' => $conceptId, 'quantity' => $quantity,
                'source' => $d['source'], 'source_type' => $d['source_type'], 'source_id' => $d['source_id'], 'retro_period_id' => $d['retro'], 'status' => 'approved',
            ]);
            $stats['created']++;
            if ($d['source'] === 'retroactive') {
                $stats['differences']++;
            }
        }
        foreach ($existing as $gone) {
            $gone->delete();
            $stats['removed']++;
        }
    }
}
