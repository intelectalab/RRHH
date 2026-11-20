<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Engine\CalculationContext;
use App\Modules\Payroll\Domain\Engine\CalculationEngine;
use App\Modules\Payroll\Domain\Engine\ConceptDefinition;
use App\Modules\Payroll\Domain\Engine\ConceptDependencies;
use App\Modules\Payroll\Domain\Engine\CycleDetected;
use App\Modules\Payroll\Domain\Engine\DependencyGraph;
use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\Parser;
use App\Modules\Payroll\Infrastructure\ConceptCatalog;
use App\Modules\Payroll\Infrastructure\ContextBuilder;
use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Modules\Payroll\Infrastructure\IncomeTaxWithholding;
use App\Modules\Payroll\Infrastructure\PayrollParameters;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * NOM-1 · Servicio del motor de nómina: la única forma de calcular (la interfaz nunca calcula montos).
 * Arma el contexto (NOM-5), toma los conceptos vigentes a la fecha del periodo, los parámetros del país y
 * del régimen y ejecuta el núcleo. Guardar una corrida conserva contexto, parámetros y traza (RN-NOM-04).
 */
final class PayrollEngine
{
    public function __construct(
        private readonly ConceptCatalog $catalog,
        private readonly ContextBuilder $contexts,
        private readonly LegalParameters $legal,
        private readonly CalculationEngine $engine,
        private readonly IncomeTaxWithholding $incomeTax,
    ) {}

    /**
     * NOM-4 · Orden determinista de cálculo de los conceptos vigentes a la fecha (opcional: de un régimen y
     * tipo de proceso), con las dependencias de cada uno. 422 cycle si las fórmulas forman un ciclo.
     *
     * @return list<array{position: int, code: string, name: string, kind: string, depends_on: list<string>}>
     */
    public function order(string $asOf, ?string $regime = null, ?string $periodKind = null): array
    {
        $concepts = [];
        foreach ($this->catalog->at($asOf) as $c) {
            if (($regime === null || $c->appliesToRegime($regime)) && ($periodKind === null || $c->appliesToKind($periodKind))) {
                $concepts[$c->code] = $c;
            }
        }
        $deps = [];
        $sort = [];
        $parser = new Parser;
        foreach ($concepts as $code => $c) {
            $sort[$code] = $c->sortOrder;
            try {
                $deps[$code] = ConceptDependencies::of($c, $concepts, $parser)['depends_on'];
            } catch (FormulaError $e) {
                throw new AyniException('formula_invalid', 422, (string) trans('payroll.formula_invalid', ['concept' => $code]), [], ['concept' => $code] + $e->toArray());
            }
        }
        try {
            $order = DependencyGraph::order($deps, $sort);
        } catch (CycleDetected $e) {
            throw new AyniException('cycle', 422, (string) trans('payroll.cycle', ['concepts' => implode(' → ', $e->concepts)]), [], ['concepts' => $e->concepts]);
        }
        $out = [];
        foreach ($order as $i => $code) {
            $c = $concepts[$code];
            $out[] = ['position' => $i + 1, 'code' => $code, 'name' => $c->name, 'kind' => $c->kind,
                'depends_on' => array_values(array_filter($deps[$code], fn ($d) => isset($concepts[$d])))];
        }

        return $out;
    }

    /**
     * Calcula una relación laboral en un periodo sin guardar nada (banco de pruebas, simulación, recálculo en
     * sombra). NOM-9: un cambio de régimen dentro del periodo calcula cada tramo con la estrategia y los
     * parámetros de su régimen y conserva la traza por tramo; un tipo de proceso que el régimen no genera
     * (p. ej. gratificación en microempresa) deja la relación «excluida» con su motivo. NOM-7: las diferencias
     * retroactivas del periodo entran como líneas propias con su periodo de origen.
     *
     * @param  array<string, string|bool>  $overrides
     * @param  array<string, array{amount?: string, quantity?: string}>  $incidents
     * @param  ConceptDefinition|null  $candidate  CPT-2 · versión en borrador de un concepto que reemplaza a la vigente (probador)
     * @return array{employment_id: string, from: string, to: string, period_kind: string, context: array<string, mixed>, result: array<string, mixed>}
     */
    public function calculate(string $employmentId, string $from, string $to, string $periodKind = 'regular', ?string $periodId = null, array $overrides = [], array $incidents = [], ?ConceptDefinition $candidate = null): array
    {
        $built = $this->contexts->build($employmentId, $from, $to, $periodKind, $periodId, $overrides, $incidents);
        $context = $built['context'];
        $pack = new DataPayrollCountryPack($built['country']);
        $strategy = $context->regime === null ? null : $pack->regime($context->regime);
        $concepts = $this->catalog->at($context->to);
        if ($candidate !== null) {
            $concepts = array_values(array_filter($concepts, fn (ConceptDefinition $c) => $c->code !== $candidate->code));
            $concepts[] = $candidate;
        }

        if ($context->errors === [] && $strategy !== null && ! in_array($periodKind, $strategy['processes'], true)) {
            $result = ['status' => 'excluded', 'errors' => [], 'order' => [], 'lines' => [], 'totals' => [], 'engine_version' => CalculationEngine::VERSION,
                'warnings' => [['code' => 'process_not_applicable', 'message' => (string) trans('payroll.process_not_applicable', ['kind' => $periodKind, 'regime' => (string) $context->regime])]]];
        } elseif ($built['tramos'] === []) {
            $params = new PayrollParameters($this->legal, $built['country'], $context->regime, $built['legal_entity_id'], $context->to, $employmentId);
            $result = $this->engine->run($context, $concepts, $params, $built['decimals']);
        } else {
            $result = $this->byTramos($built['tramos'], $built, $concepts, $employmentId);
        }
        if ($periodId !== null && $result['status'] === 'calculated') {
            $retro = $this->retroactiveLines($employmentId, $periodId, $context->currency);
            if ($retro !== []) {
                $result['lines'] = array_values(array_merge($result['lines'], $retro));
                $result['totals'] = CalculationEngine::totals($result['lines'], $built['decimals']);
            }
        }
        if ($result['status'] === 'calculated') {
            // PER-5 · Retención del impuesto a la renta del trabajador con la planilla ya calculada (sus bases).
            $taxParams = new PayrollParameters($this->legal, $built['country'], $context->regime, $built['legal_entity_id'], $context->to, $employmentId);
            $result = $this->incomeTax->apply($result, $built['country'], $employmentId, $periodId, $from, $to, $periodKind, $context->regime,
                $context->variables, $taxParams, $built['decimals']);
        }
        $result['pack_version'] = $built['pack_version'];
        $result['strategy'] = $strategy === null ? null : ['regime' => $strategy['code'], 'strategy' => $strategy['strategy'], 'pay_unit' => $strategy['pay_unit']];

        return ['employment_id' => $employmentId, 'from' => $from, 'to' => $to, 'period_kind' => $periodKind, 'context' => $context->snapshot()
            + ['tramos' => array_map(fn (CalculationContext $t) => $t->snapshot(), $built['tramos'])], 'result' => $result];
    }

    /**
     * NOM-9 · Corre el núcleo una vez por tramo de régimen (cada uno con los parámetros de su régimen) y suma
     * las líneas por concepto; la traza de cada línea guarda el detalle de cada tramo.
     *
     * @param  list<CalculationContext>  $tramos
     * @param  array{country: string, legal_entity_id: string, decimals: int}  $built
     * @param  list<ConceptDefinition>  $concepts
     * @return array<string, mixed>
     */
    private function byTramos(array $tramos, array $built, array $concepts, string $employmentId): array
    {
        $merged = [];
        $order = [];
        $warnings = [];
        $errors = [];
        foreach ($tramos as $i => $t) {
            $params = new PayrollParameters($this->legal, $built['country'], $t->regime, $built['legal_entity_id'], (string) $t->variables['fecha_fin'], $employmentId);
            $r = $this->engine->run($t, $concepts, $params, $built['decimals']);
            $tramo = ['index' => $i + 1, 'from' => (string) $t->variables['fecha_inicio'], 'to' => (string) $t->variables['fecha_fin'], 'regime' => (string) $t->regime];
            foreach ($r['errors'] as $e) {
                $errors[] = $e + ['tramo' => $tramo];
            }
            foreach ($r['warnings'] as $w) {
                $warnings[$w['code'].'|'.($w['parameter'] ?? '')] = $w;
            }
            foreach ($r['order'] as $code) {
                if (! in_array($code, $order, true)) {
                    $order[] = $code;
                }
            }
            foreach ($r['lines'] as $line) {
                $code = $line['concept_code'];
                $entry = $tramo + ['amount' => $line['amount']['amount'], 'concept_version_id' => $line['concept_version_id'], 'trace' => $line['trace']];
                if (! isset($merged[$code])) {
                    $merged[$code] = $line;
                    $merged[$code]['trace'] = ['source' => 'tramos', 'formula' => $line['trace']['formula'] ?? null,
                        'version_valid_from' => $line['trace']['version_valid_from'] ?? null, 'parameters' => [], 'tramos' => [$entry]];
                } else {
                    $merged[$code]['amount']['amount'] = (string) BigDecimal::of($merged[$code]['amount']['amount'])->plus(BigDecimal::of($line['amount']['amount']));
                    $merged[$code]['trace']['tramos'][] = $entry;
                }
                foreach ($line['trace']['parameters'] as $p) {
                    $merged[$code]['trace']['parameters'][$p['code'].'@'.$p['valid_from'].'@'.$tramo['regime']] = $p;
                }
            }
        }
        foreach ($merged as $code => $line) {
            $merged[$code]['trace']['parameters'] = array_values($line['trace']['parameters']);
        }
        if ($errors !== []) {
            return ['status' => 'error', 'errors' => $errors, 'warnings' => array_values($warnings), 'order' => $order, 'lines' => [], 'totals' => [], 'engine_version' => CalculationEngine::VERSION];
        }
        $lines = array_values($merged);

        return ['status' => 'calculated', 'errors' => [], 'warnings' => array_values($warnings), 'order' => $order, 'lines' => $lines,
            'totals' => CalculationEngine::totals($lines, $built['decimals']), 'engine_version' => CalculationEngine::VERSION];
    }

    /**
     * NOM-7 · Diferencias retroactivas pendientes o aprobadas del periodo: una línea por incidencia con el
     * periodo de origen en su traza (el periodo cerrado nunca se toca).
     *
     * @return list<array<string, mixed>>
     */
    private function retroactiveLines(string $employmentId, string $periodId, string $currency): array
    {
        $rows = DB::table('payroll_incidents AS i')->join('payroll_concepts AS c', 'c.id', '=', 'i.payroll_concept_id')
            ->leftJoin('payroll_periods AS o', 'o.id', '=', 'i.retro_period_id')
            ->where('i.payroll_period_id', $periodId)->where('i.employment_id', $employmentId)->where('i.source', 'retroactive')
            ->whereNull('i.deleted_at')->whereIn('i.status', ['pending', 'approved', 'applied'])->orderBy('o.starts_on')->orderBy('c.code')
            ->get(['i.id', 'i.amount', 'i.currency', 'i.notes', 'c.id AS concept_id', 'c.code', 'c.name', 'c.kind', 'o.id AS origin_id', 'o.period_year', 'o.period_number', 'o.starts_on', 'o.ends_on']);
        $out = [];
        foreach ($rows as $r) {
            $label = $r->period_year === null ? '' : sprintf('%04d-%02d', (int) $r->period_year, (int) $r->period_number);
            $out[] = [
                'concept_code' => (string) $r->code, 'concept_name' => (string) $r->name, 'kind' => (string) $r->kind,
                'concept_id' => (string) $r->concept_id, 'concept_version_id' => null, 'origin' => 'retroactive', 'incident_id' => (string) $r->id,
                'amount' => ['amount' => (string) BigDecimal::of((string) ($r->amount ?? '0')), 'currency' => (string) ($r->currency ?? $currency)],
                'trace' => ['source' => 'retroactive', 'formula' => null, 'parameters' => [], 'notes' => $r->notes,
                    'origin_period' => ['id' => $r->origin_id, 'label' => $label, 'from' => $r->starts_on === null ? null : substr((string) $r->starts_on, 0, 10),
                        'to' => $r->ends_on === null ? null : substr((string) $r->ends_on, 0, 10)]],
            ];
        }

        return $out;
    }

    /**
     * Calcula y guarda una corrida de un periodo en el acto (corridas pequeñas y pruebas). Las grandes van en
     * segundo plano con PayrollRuns (NOM-8), que usa el mismo procesamiento por relación.
     *
     * @param  list<string>  $employmentIds
     */
    public function storeRun(string $periodId, array $employmentIds, string $kind, ?string $userId): string
    {
        $runs = app(PayrollRuns::class);
        $runId = $runs->open($periodId, ['employment_ids' => $employmentIds], $kind, $userId, false);
        $runs->process($runId);

        return $runId;
    }
}
