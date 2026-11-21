<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\ParallelComparisonStateMachine;
use App\Modules\Payroll\Infrastructure\Models\PayrollParallelComparison;
use App\Modules\Payroll\Infrastructure\Models\PayrollParallelDifference;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Exceptions\AyniException;
use App\Shared\Settings\ParameterResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * PER-13 · Planilla en paralelo durante la migración: compara la corrida vigente del periodo con el resultado del
 * sistema anterior (importado con legacy_payroll_results) por trabajador y concepto. Una diferencia dentro de la
 * tolerancia (nomina.paralelo_tolerancia) queda clasificada como redondeo y explicada; las demás esperan su
 * clasificación (dato de entrada, parámetro, regla o redondeo) con comentario. La comparación queda «lista» solo
 * cuando todas están explicadas, y entonces se archiva el informe en el legajo de la empresa.
 */
final class ParallelPayroll
{
    public function __construct(
        private readonly PayrollReview $review,
        private readonly ParameterResolver $parameters,
        private readonly DocumentArchive $archive,
        private readonly AuditLogger $audit,
        private readonly ParallelComparisonStateMachine $machine,
    ) {}

    /**
     * Compara la corrida vigente del periodo con lo importado. Cada comparación es una foto nueva: las anteriores
     * quedan como historia.
     *
     * @return array<string, mixed>
     */
    public function compare(string $periodId, ?string $tolerance, ?string $legacySystem, ?string $userId): array
    {
        $runId = $this->review->currentRun($periodId);
        if ($runId === null) {
            throw new AyniException('no_payroll_run', 409, (string) trans('payroll.parallel.no_run'));
        }
        if (! DB::table('payroll_legacy_results')->where('payroll_period_id', $periodId)->whereNull('deleted_at')->exists()) {
            throw new AyniException('no_legacy_results', 409, (string) trans('payroll.parallel.no_legacy'));
        }
        $entity = (string) DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('p.id', $periodId)->value('g.legal_entity_id');
        $currency = (string) DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('p.id', $periodId)->value('g.currency');
        $tol = BigDecimal::of($tolerance ?? (string) ($this->parameters->value('nomina.paralelo_tolerancia', $entity) ?? '0'))->abs()->toScale(2, RoundingMode::HALF_UP);

        $product = [];
        foreach (DB::table('payroll_run_lines AS l')->join('payroll_run_employments AS e', 'e.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')->where('l.payroll_run_id', $runId)
            ->groupBy('e.employment_id', 'c.code')->selectRaw('e.employment_id, c.code, SUM(l.amount) AS amount')->get() as $r) {
            $product[$r->employment_id.'|'.$r->code] = (string) $r->amount;
        }
        $legacy = [];
        foreach (DB::table('payroll_legacy_results')->where('payroll_period_id', $periodId)->whereNull('deleted_at')
            ->groupBy('employment_id', 'concept_code')->selectRaw('employment_id, concept_code, SUM(amount) AS amount')->get() as $r) {
            $legacy[$r->employment_id.'|'.$r->concept_code] = (string) $r->amount;
        }
        // Solo los trabajadores que vienen en el archivo del sistema anterior: el paralelo puede ser parcial.
        $employments = array_unique(array_map(fn (string $k): string => explode('|', $k)[0], array_keys($legacy)));

        return DB::transaction(function () use ($periodId, $runId, $tol, $legacySystem, $currency, $userId, $product, $legacy, $employments): array {
            $c = PayrollParallelComparison::query()->create([
                'payroll_period_id' => $periodId, 'payroll_run_id' => $runId, 'legacy_system' => $legacySystem, 'tolerance' => (string) $tol,
                'currency' => $currency, 'status' => 'open', 'created_by_user_id' => $userId,
            ]);
            $byConcept = [];
            $rows = 0;
            $unexplained = 0;
            foreach (array_unique(array_merge(array_keys($product), array_keys($legacy))) as $key) {
                [$employment, $code] = explode('|', $key);
                if (! in_array($employment, $employments, true)) {
                    continue;
                }
                $old = isset($legacy[$key]) ? BigDecimal::of($legacy[$key]) : null;
                $new = isset($product[$key]) ? BigDecimal::of($product[$key]) : null;
                $diff = ($new ?? BigDecimal::zero())->minus($old ?? BigDecimal::zero())->toScale(2, RoundingMode::HALF_UP);
                $agg = $byConcept[$code] ?? ['concept_code' => $code, 'legacy' => BigDecimal::zero(), 'product' => BigDecimal::zero(), 'differences' => 0];
                $agg['legacy'] = $agg['legacy']->plus($old ?? BigDecimal::zero());
                $agg['product'] = $agg['product']->plus($new ?? BigDecimal::zero());
                if ($diff->isZero()) {
                    $byConcept[$code] = $agg;

                    continue;
                }
                $agg['differences']++;
                $byConcept[$code] = $agg;
                $within = $diff->abs()->isLessThanOrEqualTo($tol);
                $unexplained += $within ? 0 : 1;
                $rows++;
                PayrollParallelDifference::query()->create([
                    'payroll_parallel_comparison_id' => $c->id, 'employment_id' => $employment, 'concept_code' => $code,
                    'legacy_amount' => $old === null ? null : (string) $old->toScale(2), 'product_amount' => $new === null ? null : (string) $new->toScale(2),
                    'difference' => (string) $diff, 'currency' => $currency, 'classification' => $within ? 'rounding' : 'unclassified', 'explained' => $within,
                    'comment' => $within ? (string) trans('payroll.parallel.within_tolerance') : null,
                ]);
            }
            ksort($byConcept);
            $c->summary = [
                'employments' => count($employments), 'differences' => $rows, 'unexplained' => $unexplained,
                'by_concept' => array_values(array_map(fn (array $a): array => [
                    'concept_code' => $a['concept_code'], 'legacy' => (string) $a['legacy']->toScale(2), 'product' => (string) $a['product']->toScale(2),
                    'difference' => (string) $a['product']->minus($a['legacy'])->toScale(2), 'differences' => $a['differences'],
                ], $byConcept)),
            ];
            $c->save();
            $this->audit->record('payroll_parallel.compared', 'payroll_period', $periodId, null, ['comparison_id' => $c->id, 'run_id' => $runId, 'differences' => $rows, 'unexplained' => $unexplained]);

            return $this->present($c, true);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $periodId, bool $amounts): array
    {
        return array_values(PayrollParallelComparison::query()->where('payroll_period_id', $periodId)->orderByDesc('created_at')->get()
            ->map(fn (PayrollParallelComparison $c): array => $this->present($c, $amounts))->all());
    }

    /** @return array<string, mixed> */
    public function present(PayrollParallelComparison $c, bool $amounts): array
    {
        $summary = $c->summary ?? [];
        if (! $amounts) {
            unset($summary['by_concept']);
        }

        return [
            'id' => $c->id, 'payroll_period_id' => $c->payroll_period_id, 'payroll_run_id' => $c->payroll_run_id, 'legacy_system' => $c->legacy_system,
            'tolerance' => ['amount' => (string) BigDecimal::of($c->tolerance)->toScale(2), 'currency' => $c->currency], 'status' => $c->status,
            'summary' => $summary, 'report_document_id' => $c->report_document_id, 'ready_at' => $c->ready_at === null ? null : (string) $c->ready_at,
            'ready_note' => $c->ready_note, 'created_at' => (string) $c->created_at,
            'allowed_actions' => $this->machine->allowedActions($c->status),
        ];
    }

    /**
     * Diferencias de la comparación dentro del alcance; los montos solo con payroll.results.view.
     *
     * @param  list<string>|null  $visible  null: todas (el informe archivado)
     * @return list<array<string, mixed>>
     */
    public function differences(string $comparisonId, ?array $visible, ?string $filter): array
    {
        $q = DB::table('payroll_parallel_differences AS d')->join('employments AS e', 'e.id', '=', 'd.employment_id')
            ->join('people AS pe', 'pe.id', '=', 'e.person_id')->where('d.payroll_parallel_comparison_id', $comparisonId)
            ->when($visible !== null, fn ($w) => $w->whereIn('d.employment_id', $visible))->orderBy('pe.paternal_surname')->orderBy('d.concept_code')
            ->select('d.*', 'e.person_id', 'e.employee_code', 'pe.first_names', 'pe.paternal_surname', 'pe.maternal_surname');
        if ($filter === 'unexplained') {
            $q->where('d.explained', false);
        } elseif ($filter === 'explained') {
            $q->where('d.explained', true);
        }

        return array_values($q->get()->map(fn ($d): array => [
            'id' => (string) $d->id, 'employment_id' => (string) $d->employment_id, 'person_id' => (string) $d->person_id,
            'worker' => trim($d->paternal_surname.' '.($d->maternal_surname ?? '')).', '.$d->first_names, 'employee_code' => $d->employee_code,
            'concept_code' => (string) $d->concept_code,
            'legacy_amount' => $d->legacy_amount === null ? null : (string) $d->legacy_amount,
            'product_amount' => $d->product_amount === null ? null : (string) $d->product_amount,
            'difference' => (string) $d->difference,
            'classification' => (string) $d->classification, 'explained' => (bool) $d->explained, 'comment' => $d->comment,
        ])->all());
    }

    /** @return array<string, mixed> */
    public function explain(PayrollParallelDifference $d, string $classification, ?string $comment, ?string $userId): array
    {
        $c = PayrollParallelComparison::query()->findOrFail($d->payroll_parallel_comparison_id);
        if ($c->status !== 'open') {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.parallel.not_open'));
        }
        if ($classification !== 'rounding' && trim((string) $comment) === '') {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.parallel.comment_required'),
                [['field' => 'comment', 'rule' => 'required', 'message' => (string) trans('payroll.parallel.comment_required')]]);
        }
        $before = ['classification' => $d->classification, 'explained' => $d->explained, 'comment' => $d->comment];
        $d->forceFill(['classification' => $classification, 'explained' => true, 'comment' => $comment, 'explained_by_user_id' => $userId, 'explained_at' => now()])->save();
        $this->refreshCount($c);
        $this->audit->record('payroll_parallel.difference_explained', 'payroll_parallel_difference', $d->id, $before, ['classification' => $classification, 'comment' => $comment]);

        return ['id' => $d->id, 'classification' => $d->classification, 'explained' => true, 'comment' => $d->comment];
    }

    /** @return array<string, mixed> */
    public function markReady(PayrollParallelComparison $c, ?string $note, ?string $userId): array
    {
        $next = $this->machine->getNextState($c->status, 'mark_ready');
        if (PayrollParallelDifference::query()->where('payroll_parallel_comparison_id', $c->id)->where('explained', false)->exists()) {
            throw new AyniException('unexplained_differences', 409, (string) trans('payroll.parallel.unexplained'));
        }
        $p = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('p.id', $c->payroll_period_id)
            ->first(['g.legal_entity_id', 'g.name', 'p.period_year', 'p.period_number']);
        $category = $this->archive->category('parallel_payroll_report', (string) trans('payroll.parallel.category'), 'payroll.results.view');
        $title = (string) trans('payroll.parallel.report_title', ['group' => $p?->name, 'period' => sprintf('%04d-%02d', (int) $p?->period_year, (int) $p?->period_number)]);
        $documentId = $this->archive->create([
            'category_id' => $category, 'title' => $title, 'legal_entity_id' => $p === null ? null : (string) $p->legal_entity_id,
            'subject_type' => 'payroll_parallel_comparison', 'subject_id' => $c->id, 'source' => 'generated', 'status' => 'active',
            'issued_on' => now()->toDateString(),
        ], $this->csv($c), 'paralelo-'.substr($c->id, 0, 8).'.csv', 'text/csv', $userId);
        $c->forceFill(['status' => $next, 'ready_by_user_id' => $userId, 'ready_at' => now(), 'ready_note' => $note, 'report_document_id' => $documentId])->save();
        $this->audit->record('payroll_parallel.ready', 'payroll_parallel_comparison', $c->id, ['status' => 'open'], ['status' => $next, 'report_document_id' => $documentId, 'note' => $note]);

        return $this->present($c->refresh(), true);
    }

    /** @return array<string, mixed> */
    public function reopen(PayrollParallelComparison $c, ?string $reason): array
    {
        $next = $this->machine->getNextState($c->status, 'reopen');
        $c->forceFill(['status' => $next])->save();
        $this->audit->record('payroll_parallel.reopened', 'payroll_parallel_comparison', $c->id, ['status' => 'ready'], ['status' => $next, 'reason' => $reason]);

        return $this->present($c->refresh(), true);
    }

    /** Informe en CSV: una fila por diferencia con su clasificación y comentario. */
    public function csv(PayrollParallelComparison $c): string
    {
        $out = fopen('php://temp', 'r+');
        assert($out !== false);
        fputcsv($out, array_map(fn (string $k): string => (string) trans('payroll.parallel.csv.'.$k), ['worker', 'document', 'concept', 'legacy', 'product', 'difference', 'classification', 'comment']));
        foreach ($this->differences($c->id, null, null) as $d) {
            fputcsv($out, [$d['worker'], $d['employee_code'], $d['concept_code'], $d['legacy_amount'], $d['product_amount'], $d['difference'],
                (string) trans('payroll.parallel.classification.'.$d['classification']), $d['comment']]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function refreshCount(PayrollParallelComparison $c): void
    {
        $summary = $c->summary ?? [];
        $summary['unexplained'] = PayrollParallelDifference::query()->where('payroll_parallel_comparison_id', $c->id)->where('explained', false)->count();
        $c->summary = $summary;
        $c->save();
    }
}
