<?php

namespace App\Modules\Payroll\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * PER-7 · Simulación y detección de anomalías (RN-PER-05/06).
 *
 * - Al terminar una corrida (cálculo o simulación) se compara cada trabajador con su resultado del periodo anterior
 *   del mismo grupo y tipo: neto que varía más que el umbral (por empresa; sin fila, el parámetro
 *   nomina.variacion_neto_alerta_pct), concepto que varía más que su umbral, neto negativo y trabajadores sin cálculo.
 * - Cada anomalía lleva su explicación y sus causas probables (los conceptos que más explican la diferencia).
 * - Las anomalías se marcan revisadas o descartadas con comentario; las críticas abiertas impiden cerrar el periodo.
 * - La comparación (anterior, actual, variación por trabajador y por concepto) no escribe nada.
 */
final class PayrollReview
{
    private const SCALE = 2;

    public function __construct(
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** Detecta las anomalías de una corrida terminada. Idempotente: si ya tiene anomalías no hace nada. */
    public function detect(string $runId): int
    {
        $run = DB::table('payroll_runs AS r')->join('payroll_periods AS p', 'p.id', '=', 'r.payroll_period_id')
            ->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('r.id', $runId)
            ->first(['r.id', 'r.kind', 'r.payroll_period_id', 'p.payroll_group_id', 'p.kind AS period_kind', 'p.starts_on', 'g.legal_entity_id']);
        if ($run === null || DB::table('payroll_run_anomalies')->where('payroll_run_id', $runId)->exists()) {
            return 0;
        }
        $previousRun = $this->referenceRun((string) $run->payroll_group_id, (string) $run->period_kind, substr((string) $run->starts_on, 0, 10));
        $entity = (string) $run->legal_entity_id;
        $netThreshold = $this->threshold($entity, null);
        $conceptThresholds = $this->conceptThresholds($entity);
        $current = $this->employments($runId);
        $previous = $previousRun === null ? [] : $this->employments($previousRun);
        $currentLines = $this->lines($runId);
        $previousLines = $previousRun === null ? [] : $this->lines($previousRun);
        $tenant = TenancyScope::requireTenant();
        $rows = [];
        foreach ($current as $employmentId => $row) {
            if ($row['status'] === 'error') {
                $rows[] = $this->row($tenant, $runId, $employmentId, null, 'not_calculated', 'critical', (string) trans('payroll.review.not_calculated'), null, null, null, []);

                continue;
            }
            if ($row['status'] !== 'calculated' || $row['net'] === null) {
                continue;
            }
            $net = BigDecimal::of($row['net']);
            if ($net->isNegative()) {
                $rows[] = $this->row($tenant, $runId, $employmentId, null, 'negative_net', 'critical',
                    (string) trans('payroll.review.negative_net', ['net' => $this->fmt($net)]), null, (string) $net, null,
                    $this->causes($previousLines[$employmentId] ?? [], $currentLines[$employmentId] ?? []));
            }
            $prev = $previous[$employmentId]['net'] ?? null;
            if ($prev !== null && ($previous[$employmentId]['status'] ?? '') === 'calculated' && $netThreshold !== null) {
                $pct = $this->variationPct(BigDecimal::of($prev), $net);
                if ($pct !== null && $pct->abs()->isGreaterThan($netThreshold)) {
                    $causes = $this->causes($previousLines[$employmentId] ?? [], $currentLines[$employmentId] ?? []);
                    $rows[] = $this->row($tenant, $runId, $employmentId, null, $pct->isNegative() ? 'net_drop' : 'net_increase', 'warning',
                        (string) trans($pct->isNegative() ? 'payroll.review.net_drop' : 'payroll.review.net_increase', [
                            'pct' => $this->fmt($pct->abs()), 'from' => $this->fmt(BigDecimal::of($prev)), 'to' => $this->fmt($net),
                            'causes' => $this->causesText($causes),
                        ]), (string) BigDecimal::of($prev), (string) $net, (string) $pct, $causes);
                }
            }
            foreach ($conceptThresholds as $conceptId => $threshold) {
                $before = $previousLines[$employmentId][$conceptId]['amount'] ?? null;
                $after = $currentLines[$employmentId][$conceptId]['amount'] ?? null;
                if ($before === null || $after === null) {
                    continue;
                }
                $pct = $this->variationPct(BigDecimal::of($before), BigDecimal::of($after));
                if ($pct !== null && $pct->abs()->isGreaterThan($threshold)) {
                    $name = $currentLines[$employmentId][$conceptId]['name'];
                    $rows[] = $this->row($tenant, $runId, $employmentId, $conceptId, 'concept_variation', 'warning',
                        (string) trans('payroll.review.concept_variation', ['concept' => $name, 'pct' => $this->fmt($pct->abs()),
                            'from' => $this->fmt(BigDecimal::of($before)), 'to' => $this->fmt(BigDecimal::of($after))]),
                        (string) BigDecimal::of($before), (string) BigDecimal::of($after), (string) $pct, []);
                }
            }
        }
        if ($rows === []) {
            return 0;
        }
        DB::transaction(function () use ($rows, $runId, $run): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('payroll_run_anomalies')->insert($chunk);
            }
            if ($run->kind !== 'simulation') {
                $this->outbox->record(DomainEventTypes::PAYROLL_ANOMALY_DETECTED, 'payroll_run', $runId, [
                    'payroll_run_id' => $runId, 'period_id' => (string) $run->payroll_period_id, 'count' => count($rows),
                    'critical' => count(array_filter($rows, fn (array $r): bool => $r['severity'] === 'critical')),
                ]);
            }
        });

        return count($rows);
    }

    /**
     * Comparación del periodo contra el anterior por trabajador y por concepto (anterior, actual, variación), con las
     * anomalías de la corrida. No escribe nada.
     *
     * @param  list<string>|null  $visible  relaciones dentro del alcance de quien consulta (null = todas)
     * @return array<string, mixed>
     */
    public function variance(string $periodId, ?string $runId, ?array $visible): array
    {
        $p = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('p.id', $periodId)
            ->first(['p.id', 'p.payroll_group_id', 'p.kind', 'p.starts_on', 'g.legal_entity_id', 'g.currency']);
        if ($p === null) {
            throw new AyniException('not_found', 404);
        }
        $runId ??= $this->currentRun($periodId);
        if ($runId !== null && ! DB::table('payroll_runs')->where('id', $runId)->where('payroll_period_id', $periodId)->exists()) {
            throw new AyniException('not_found', 404);
        }
        $previousRun = $this->referenceRun((string) $p->payroll_group_id, (string) $p->kind, substr((string) $p->starts_on, 0, 10));
        $current = $runId === null ? [] : $this->employments($runId);
        $previous = $previousRun === null ? [] : $this->employments($previousRun);
        $currentLines = $runId === null ? [] : $this->lines($runId);
        $previousLines = $previousRun === null ? [] : $this->lines($previousRun);
        $threshold = $this->threshold((string) $p->legal_entity_id, null);
        $ids = array_values(array_unique(array_merge(array_keys($current), array_keys($previous))));
        if ($visible !== null) {
            $ids = array_values(array_intersect($ids, $visible));
        }
        sort($ids);
        $names = DB::table('employments AS e')->join('people AS pp', 'pp.id', '=', 'e.person_id')->whereIn('e.id', $ids)
            ->get(['e.id', 'e.employee_code', 'pp.first_names', 'pp.paternal_surname', 'pp.maternal_surname'])->keyBy('id');
        $employees = [];
        $concepts = [];
        foreach ($ids as $id) {
            $before = $previous[$id]['net'] ?? null;
            $after = $current[$id]['net'] ?? null;
            $pct = $before !== null && $after !== null ? $this->variationPct(BigDecimal::of($before), BigDecimal::of($after)) : null;
            $n = $names[$id] ?? null;
            $employees[] = [
                'employment_id' => $id, 'employee_code' => $n?->employee_code,
                'employee_name' => $n === null ? '' : trim($n->first_names.' '.$n->paternal_surname.' '.($n->maternal_surname ?? '')),
                'status' => $current[$id]['status'] ?? 'not_calculated',
                'previous_net' => $before, 'current_net' => $after,
                'variation' => $before !== null && $after !== null ? (string) BigDecimal::of($after)->minus($before) : null,
                'variation_pct' => $pct === null ? null : (string) $pct,
                'exceeds_threshold' => $pct !== null && $threshold !== null && $pct->abs()->isGreaterThan($threshold),
            ];
            foreach ([['prev', $previousLines[$id] ?? []], ['curr', $currentLines[$id] ?? []]] as [$side, $lines]) {
                foreach ($lines as $conceptId => $l) {
                    $concepts[$conceptId] ??= ['concept_id' => $conceptId, 'code' => $l['code'], 'name' => $l['name'], 'prev' => BigDecimal::zero(), 'curr' => BigDecimal::zero()];
                    $concepts[$conceptId][$side] = $concepts[$conceptId][$side]->plus($l['amount']);
                }
            }
        }
        $conceptRows = array_values(array_map(function (array $c): array {
            $pct = $this->variationPct($c['prev'], $c['curr']);

            return ['concept_id' => $c['concept_id'], 'code' => $c['code'], 'name' => $c['name'], 'previous' => (string) $c['prev'],
                'current' => (string) $c['curr'], 'variation' => (string) $c['curr']->minus($c['prev']), 'variation_pct' => $pct === null ? null : (string) $pct];
        }, $concepts));
        usort($conceptRows, fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));

        return [
            'period_id' => $periodId, 'run_id' => $runId, 'previous_run_id' => $previousRun, 'currency' => (string) $p->currency,
            'threshold_pct' => $threshold === null ? null : (string) $threshold,
            'employees' => $employees, 'concepts' => $conceptRows,
            'anomalies' => $runId === null ? [] : $this->anomalies($runId, $visible),
        ];
    }

    /**
     * @param  list<string>|null  $visible
     * @return list<array<string, mixed>>
     */
    public function anomalies(string $runId, ?array $visible = null): array
    {
        $q = DB::table('payroll_run_anomalies AS a')->leftJoin('employments AS e', 'e.id', '=', 'a.employment_id')
            ->leftJoin('people AS pp', 'pp.id', '=', 'e.person_id')->where('a.payroll_run_id', $runId)
            ->orderByRaw("CASE a.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('a.id');
        if ($visible !== null) {
            $q->where(fn ($w) => $w->whereNull('a.employment_id')->orWhereIn('a.employment_id', $visible));
        }

        return array_values($q->get(['a.*', 'e.employee_code', 'pp.first_names', 'pp.paternal_surname', 'pp.maternal_surname'])
            ->map(fn ($a): array => $this->presentAnomaly($a))->all());
    }

    /** @return array<string, mixed> */
    public function review(string $anomalyId, string $action, ?string $note, ?string $userId): array
    {
        $a = DB::table('payroll_run_anomalies')->where('id', $anomalyId)->first();
        if ($a === null) {
            throw new AyniException('not_found', 404);
        }
        if ($a->status !== 'open') {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.review.already_reviewed'));
        }
        $note = $note === null || trim($note) === '' ? null : trim($note);
        if ($note === null) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.review.note_required'),
                [['field' => 'note', 'rule' => 'required', 'message' => (string) trans('payroll.review.note_required')]]);
        }
        $status = $action === 'dismiss' ? 'dismissed' : 'reviewed';
        DB::transaction(function () use ($anomalyId, $status, $note, $userId): void {
            DB::table('payroll_run_anomalies')->where('id', $anomalyId)->update([
                'status' => $status, 'review_note' => $note, 'reviewed_by_user_id' => $userId, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('payroll_anomaly.'.$status, 'payroll_run_anomaly', $anomalyId, ['status' => 'open'], ['status' => $status], $note);
        });

        return $this->anomaly($anomalyId);
    }

    /** @return array<string, mixed> */
    public function anomaly(string $anomalyId): array
    {
        $a = DB::table('payroll_run_anomalies AS a')->leftJoin('employments AS e', 'e.id', '=', 'a.employment_id')
            ->leftJoin('people AS pp', 'pp.id', '=', 'e.person_id')->where('a.id', $anomalyId)
            ->first(['a.*', 'e.employee_code', 'pp.first_names', 'pp.paternal_surname', 'pp.maternal_surname']);
        if ($a === null) {
            throw new AyniException('not_found', 404);
        }

        return $this->presentAnomaly($a);
    }

    /** Anomalías abiertas de la corrida vigente del periodo, por severidad.
     *
     * @return array{open: int, critical: int}
     */
    public function openCounts(string $periodId): array
    {
        $run = $this->currentRun($periodId);
        if ($run === null) {
            return ['open' => 0, 'critical' => 0];
        }
        $q = DB::table('payroll_run_anomalies')->where('payroll_run_id', $run)->where('status', 'open');

        return ['open' => (clone $q)->count(), 'critical' => (clone $q)->where('severity', 'critical')->count()];
    }

    /** Última corrida de cálculo vigente del periodo (no simulación ni descartada). */
    public function currentRun(string $periodId): ?string
    {
        $id = DB::table('payroll_runs')->where('payroll_period_id', $periodId)->whereIn('kind', ['calculation', 'recalculation'])
            ->where('status', '<>', 'discarded')->orderByDesc('run_number')->value('id');

        return $id === null ? null : (string) $id;
    }

    /** @return list<array<string, mixed>> */
    public function thresholds(?string $legalEntityId): array
    {
        $q = DB::table('payroll_variance_thresholds AS t')->leftJoin('payroll_concepts AS c', 'c.id', '=', 't.payroll_concept_id')
            ->whereNull('t.deleted_at');
        if ($legalEntityId !== null) {
            $q->where(fn ($w) => $w->whereNull('t.legal_entity_id')->orWhere('t.legal_entity_id', $legalEntityId));
        }

        return array_values($q->orderBy('t.legal_entity_id')->orderBy('c.code')->get(['t.id', 't.legal_entity_id', 't.payroll_concept_id', 't.threshold_pct', 'c.code', 'c.name'])
            ->map(fn ($t): array => array_filter(['id' => (string) $t->id, 'legal_entity_id' => $t->legal_entity_id, 'payroll_concept_id' => $t->payroll_concept_id,
                'concept_code' => $t->code, 'concept_name' => $t->name, 'threshold_pct' => (string) BigDecimal::of((string) $t->threshold_pct)], fn ($v) => $v !== null))->all());
    }

    /**
     * Crea o reemplaza el umbral de una empresa (o de la organización) para el neto o un concepto.
     *
     * @return array<string, mixed>
     */
    public function setThreshold(?string $legalEntityId, ?string $conceptId, string $pct): array
    {
        $existing = DB::table('payroll_variance_thresholds')->whereNull('deleted_at')
            ->where(fn ($q) => $legalEntityId === null ? $q->whereNull('legal_entity_id') : $q->where('legal_entity_id', $legalEntityId))
            ->where(fn ($q) => $conceptId === null ? $q->whereNull('payroll_concept_id') : $q->where('payroll_concept_id', $conceptId))->first(['id', 'threshold_pct']);
        $id = DB::transaction(function () use ($existing, $legalEntityId, $conceptId, $pct): string {
            if ($existing !== null) {
                DB::table('payroll_variance_thresholds')->where('id', $existing->id)->update(['threshold_pct' => $pct, 'updated_at' => now()]);
                $this->audit->record('payroll_variance_threshold.updated', 'payroll_variance_threshold', (string) $existing->id,
                    ['threshold_pct' => (string) $existing->threshold_pct], ['threshold_pct' => $pct]);

                return (string) $existing->id;
            }
            $id = (string) DB::table('payroll_variance_thresholds')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $legalEntityId, 'payroll_concept_id' => $conceptId, 'threshold_pct' => $pct,
            ]);
            $this->audit->record('payroll_variance_threshold.created', 'payroll_variance_threshold', $id, null,
                ['legal_entity_id' => $legalEntityId, 'payroll_concept_id' => $conceptId, 'threshold_pct' => $pct]);

            return $id;
        });
        $row = collect($this->thresholds(null))->firstWhere('id', $id);

        return is_array($row) ? $row : ['id' => $id];
    }

    public function deleteThreshold(string $id): void
    {
        $row = DB::table('payroll_variance_thresholds')->where('id', $id)->whereNull('deleted_at')->first(['id', 'threshold_pct']);
        if ($row === null) {
            throw new AyniException('not_found', 404);
        }
        DB::table('payroll_variance_thresholds')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit->record('payroll_variance_threshold.deleted', 'payroll_variance_threshold', $id, ['threshold_pct' => (string) $row->threshold_pct], null);
    }

    /** Corrida de referencia del periodo anterior del mismo grupo y tipo: la última no descartada ni simulada. */
    private function referenceRun(string $groupId, string $kind, string $startsOn): ?string
    {
        $prev = DB::table('payroll_periods')->where('payroll_group_id', $groupId)->where('kind', $kind)->where('starts_on', '<', $startsOn)
            ->orderByDesc('starts_on')->value('id');
        if ($prev === null) {
            return null;
        }
        $id = DB::table('payroll_runs')->where('payroll_period_id', $prev)->whereIn('kind', ['calculation', 'recalculation'])
            ->whereIn('status', ['completed', 'approved', 'closed'])->orderByDesc('run_number')->value('id');

        return $id === null ? null : (string) $id;
    }

    private function threshold(string $legalEntityId, ?string $conceptId): ?BigDecimal
    {
        $row = DB::table('payroll_variance_thresholds')->whereNull('deleted_at')
            ->where(fn ($q) => $conceptId === null ? $q->whereNull('payroll_concept_id') : $q->where('payroll_concept_id', $conceptId))
            ->where(fn ($q) => $q->where('legal_entity_id', $legalEntityId)->orWhereNull('legal_entity_id'))
            ->orderByRaw('legal_entity_id IS NULL')->value('threshold_pct');
        if ($row !== null) {
            return BigDecimal::of((string) $row);
        }
        if ($conceptId !== null) {
            return null;
        }
        $raw = $this->parameters->value('nomina.variacion_neto_alerta_pct', $legalEntityId);

        return is_numeric($raw) ? BigDecimal::of((string) $raw) : null;
    }

    /** @return array<string, BigDecimal> */
    private function conceptThresholds(string $legalEntityId): array
    {
        $out = [];
        $rows = DB::table('payroll_variance_thresholds')->whereNull('deleted_at')->whereNotNull('payroll_concept_id')
            ->where(fn ($q) => $q->where('legal_entity_id', $legalEntityId)->orWhereNull('legal_entity_id'))
            ->orderByRaw('legal_entity_id IS NOT NULL')->get(['payroll_concept_id', 'threshold_pct']);
        foreach ($rows as $r) {
            // La fila de la empresa (ordenada al final) reemplaza a la de la organización.
            $out[(string) $r->payroll_concept_id] = BigDecimal::of((string) $r->threshold_pct);
        }

        return $out;
    }

    /** @return array<string, array{status: string, net: string|null}> */
    private function employments(string $runId): array
    {
        $out = [];
        foreach (DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->get(['employment_id', 'status', 'net_amount']) as $r) {
            $out[(string) $r->employment_id] = ['status' => (string) $r->status, 'net' => $r->net_amount === null ? null : (string) $r->net_amount];
        }

        return $out;
    }

    /** @return array<string, array<string, array{code: string, name: string, amount: BigDecimal}>> */
    private function lines(string $runId): array
    {
        $out = [];
        $rows = DB::table('payroll_run_lines AS l')->join('payroll_run_employments AS r', 'r.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')->where('l.payroll_run_id', $runId)
            ->get(['r.employment_id', 'l.payroll_concept_id', 'l.amount', 'c.code', 'c.name']);
        foreach ($rows as $l) {
            $e = (string) $l->employment_id;
            $c = (string) $l->payroll_concept_id;
            $prev = $out[$e][$c]['amount'] ?? BigDecimal::zero();
            $out[$e][$c] = ['code' => (string) $l->code, 'name' => (string) $l->name, 'amount' => $prev->plus((string) $l->amount)];
        }

        return $out;
    }

    /**
     * Conceptos que más explican la diferencia del trabajador (los tres de mayor variación absoluta).
     *
     * @param  array<string, array{code: string, name: string, amount: BigDecimal}>  $before
     * @param  array<string, array{code: string, name: string, amount: BigDecimal}>  $after
     * @return list<array{concept_code: string, concept_name: string, previous: string, current: string, difference: string}>
     */
    private function causes(array $before, array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $c) {
            $b = $before[$c]['amount'] ?? BigDecimal::zero();
            $a = $after[$c]['amount'] ?? BigDecimal::zero();
            $diff = $a->minus($b);
            if ($diff->isZero()) {
                continue;
            }
            $out[] = ['concept_code' => $after[$c]['code'] ?? $before[$c]['code'], 'concept_name' => $after[$c]['name'] ?? $before[$c]['name'],
                'previous' => (string) $b, 'current' => (string) $a, 'difference' => (string) $diff];
        }
        usort($out, fn (array $x, array $y): int => BigDecimal::of($y['difference'])->abs()->compareTo(BigDecimal::of($x['difference'])->abs()) ?: strcmp($x['concept_code'], $y['concept_code']));

        return array_slice($out, 0, 3);
    }

    /** @param list<array{concept_name: string, difference: string}> $causes */
    private function causesText(array $causes): string
    {
        if ($causes === []) {
            return (string) trans('payroll.review.no_causes');
        }

        return implode('; ', array_map(fn (array $c): string => $c['concept_name'].' '.(BigDecimal::of($c['difference'])->isNegative() ? '' : '+').$this->fmt(BigDecimal::of($c['difference'])), $causes));
    }

    private function variationPct(BigDecimal $before, BigDecimal $after): ?BigDecimal
    {
        if ($before->isZero()) {
            return null;
        }

        return $after->minus($before)->multipliedBy(100)->dividedBy($before->abs(), 4, RoundingMode::HALF_UP);
    }

    private function fmt(BigDecimal $v): string
    {
        return (string) $v->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * @param  list<array<string, string>>  $causes
     * @return array<string, mixed>
     */
    private function row(string $tenant, string $runId, string $employmentId, ?string $conceptId, string $rule, string $severity, string $explanation, ?string $prev, ?string $curr, ?string $pct, array $causes): array
    {
        return [
            'tenant_id' => $tenant, 'payroll_run_id' => $runId, 'employment_id' => $employmentId, 'payroll_concept_id' => $conceptId, 'rule_code' => $rule,
            'severity' => $severity, 'explanation' => $explanation,
            'previous_value' => $prev === null ? null : (string) BigDecimal::of($prev)->toScale(2, RoundingMode::HALF_UP),
            'current_value' => $curr === null ? null : (string) BigDecimal::of($curr)->toScale(2, RoundingMode::HALF_UP),
            'variation_pct' => $pct, 'causes' => $causes === [] ? null : json_encode($causes),
        ];
    }

    /** @return array<string, mixed> */
    private function presentAnomaly(\stdClass $a): array
    {
        return array_filter([
            'id' => (string) $a->id, 'payroll_run_id' => (string) $a->payroll_run_id, 'employment_id' => $a->employment_id === null ? null : (string) $a->employment_id,
            'employee_code' => $a->employee_code ?? null,
            'employee_name' => isset($a->first_names) ? trim($a->first_names.' '.$a->paternal_surname.' '.($a->maternal_surname ?? '')) : null,
            'payroll_concept_id' => $a->payroll_concept_id === null ? null : (string) $a->payroll_concept_id,
            'rule_code' => (string) $a->rule_code, 'severity' => (string) $a->severity, 'explanation' => (string) $a->explanation,
            'previous_value' => $a->previous_value === null ? null : (string) $a->previous_value,
            'current_value' => $a->current_value === null ? null : (string) $a->current_value,
            'variation_pct' => $a->variation_pct === null ? null : (string) $a->variation_pct,
            'causes' => $a->causes === null ? null : json_decode((string) $a->causes, true),
            'status' => (string) $a->status, 'review_note' => $a->review_note,
            'reviewed_by_user_id' => $a->reviewed_by_user_id === null ? null : (string) $a->reviewed_by_user_id,
            'reviewed_at' => $a->reviewed_at === null ? null : (string) $a->reviewed_at,
        ], fn ($v) => $v !== null);
    }
}
