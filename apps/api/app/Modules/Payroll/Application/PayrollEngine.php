<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Engine\CalculationEngine;
use App\Modules\Payroll\Domain\Engine\ConceptDefinition;
use App\Modules\Payroll\Domain\Engine\ConceptDependencies;
use App\Modules\Payroll\Domain\Engine\CycleDetected;
use App\Modules\Payroll\Domain\Engine\DependencyGraph;
use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\Parser;
use App\Modules\Payroll\Infrastructure\ConceptCatalog;
use App\Modules\Payroll\Infrastructure\ContextBuilder;
use App\Modules\Payroll\Infrastructure\PayrollParameters;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Tenancy\TenancyScope;
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
     * Calcula una relación laboral en un periodo sin guardar nada (banco de pruebas, simulación).
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
        $date = $context->to;
        $params = new PayrollParameters($this->legal, $built['country'], $context->regime, $built['legal_entity_id'], $date, $employmentId);
        $concepts = $this->catalog->at($date);
        if ($candidate !== null) {
            $concepts = array_values(array_filter($concepts, fn (ConceptDefinition $c) => $c->code !== $candidate->code));
            $concepts[] = $candidate;
        }
        $result = $this->engine->run($context, $concepts, $params, $built['decimals']);
        $result['pack_version'] = $built['pack_version'];

        return ['employment_id' => $employmentId, 'from' => $from, 'to' => $to, 'period_kind' => $periodKind, 'context' => $context->snapshot(), 'result' => $result];
    }

    /**
     * Calcula y guarda una corrida de un periodo: payroll_runs con la versión del motor y los parámetros
     * usados, una fila por relación con su contexto (context_snapshot) y una línea por concepto con su
     * traza. Una relación con error queda «con error» y no detiene a las demás (RN-NOM-10).
     *
     * @param  list<string>  $employmentIds
     */
    public function storeRun(string $periodId, array $employmentIds, string $kind, ?string $userId): string
    {
        $period = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')
            ->where('p.id', $periodId)->first(['p.id', 'p.starts_on', 'p.ends_on', 'p.kind', 'g.currency']);
        if ($period === null) {
            throw new AyniException('not_found', 404);
        }
        $from = substr((string) $period->starts_on, 0, 10);
        $to = substr((string) $period->ends_on, 0, 10);
        $periodKind = (string) $period->kind;

        return DB::transaction(function () use ($periodId, $employmentIds, $kind, $userId, $from, $to, $periodKind, $period): string {
            $tenant = TenancyScope::requireTenant();
            DB::table('payroll_periods')->where('id', $periodId)->lockForUpdate()->first(['id']);
            $number = (int) DB::table('payroll_runs')->where('payroll_period_id', $periodId)->max('run_number') + 1;
            $runId = (string) DB::table('payroll_runs')->insertGetId([
                'tenant_id' => $tenant, 'payroll_period_id' => $periodId, 'run_number' => $number, 'kind' => $kind, 'status' => 'running',
                'engine_version' => CalculationEngine::VERSION, 'started_at' => now(), 'requested_by_user_id' => $userId,
            ]);
            $parameters = [];
            $sorted = $employmentIds;
            sort($sorted);
            foreach ($sorted as $employmentId) {
                $calc = $this->calculate($employmentId, $from, $to, $periodKind, $periodId);
                $r = $calc['result'];
                $ok = $r['status'] === 'calculated';
                $proration = $calc['context']['sources']['proration_parameter'] ?? null;
                if (is_array($proration)) {
                    $parameters[$proration['code'].'@'.$proration['valid_from']] = $proration;
                }
                $rowId = (string) DB::table('payroll_run_employments')->insertGetId([
                    'tenant_id' => $tenant, 'payroll_run_id' => $runId, 'employment_id' => $employmentId, 'status' => $ok ? 'calculated' : 'error',
                    'gross_amount' => $ok ? $r['totals']['earnings'] : null, 'deductions_amount' => $ok ? $r['totals']['deductions'] : null,
                    'net_amount' => $ok ? $r['totals']['net'] : null, 'employer_cost' => $ok ? $r['totals']['employer_cost'] : null,
                    'currency' => $calc['context']['currency'] !== '' ? $calc['context']['currency'] : (string) $period->currency,
                    'worked_days' => $calc['context']['variables']['dias_laborados'] ?? null,
                    'context_snapshot' => json_encode($calc['context'] + ['warnings' => $r['warnings'], 'order' => $r['order']]),
                    'error' => $ok ? null : json_encode($r['errors']),
                ]);
                foreach ($r['lines'] as $line) {
                    foreach ($line['trace']['parameters'] as $p) {
                        $parameters[$p['code'].'@'.$p['valid_from']] = $p;
                    }
                    DB::table('payroll_run_lines')->insert([
                        'tenant_id' => $tenant, 'payroll_run_id' => $runId, 'payroll_run_employment_id' => $rowId,
                        'payroll_concept_id' => $line['concept_id'], 'payroll_concept_version_id' => $line['concept_version_id'],
                        'amount' => $line['amount']['amount'], 'currency' => $line['amount']['currency'], 'origin' => 'calculated',
                        'trace' => json_encode($line['trace']),
                    ]);
                }
            }
            ksort($parameters);
            DB::table('payroll_runs')->where('id', $runId)->update([
                'status' => 'completed', 'finished_at' => now(), 'parameters_snapshot' => json_encode(array_values($parameters)),
            ]);

            return $runId;
        });
    }
}
