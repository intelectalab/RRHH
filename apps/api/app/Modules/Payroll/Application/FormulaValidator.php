<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Engine\ConceptDefinition;
use App\Modules\Payroll\Domain\Engine\ConceptDependencies;
use App\Modules\Payroll\Domain\Engine\ContextVariables;
use App\Modules\Payroll\Domain\Engine\CycleDetected;
use App\Modules\Payroll\Domain\Engine\DependencyGraph;
use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\Parser;
use App\Modules\Payroll\Infrastructure\ConceptCatalog;
use App\Modules\Payroll\Infrastructure\Country\CountryConceptTemplate;
use Illuminate\Support\Facades\DB;

/**
 * NOM-3 · NOM-4 · RN-NOM-06 · Revisión de una fórmula antes de guardarla o publicarla: sintaxis, funciones,
 * datos, conceptos e incidencias que existen, parámetros del catálogo legal del país y que no forme un ciclo
 * con los conceptos vigentes. CPT-2 · El editor de conceptos la usa también para la condición y las bases
 * (base.pension…) y para no publicar una fórmula inválida (RN-CPT-04).
 */
final class FormulaValidator
{
    public function __construct(private readonly ConceptCatalog $catalog, private readonly CountryConceptTemplate $template) {}

    /**
     * @param  string|null  $condition  CPT-2 · condición de aplicación (debe dar verdadero o falso)
     * @param  list<string>|null  $affectations  CPT-3 · bases a las que sumaría el concepto (para revisar ciclos)
     * @return array{valid: bool, errors: list<array<string, mixed>>, references: array<string, list<string>>, order: list<string>, depends_on: list<string>}
     */
    public function validate(string $formula, ?string $conceptCode, string $asOf, ?string $condition = null, ?array $affectations = null): array
    {
        $empty = ['concept' => [], 'param' => [], 'incident' => [], 'quantity' => [], 'base' => [], 'var' => []];
        $out = ['valid' => false, 'errors' => [], 'references' => $empty, 'order' => [], 'depends_on' => []];
        $parser = new Parser;
        $refs = $empty;
        foreach (['formula' => $formula, 'condition' => $condition] as $part => $text) {
            if ($text === null || ($part === 'condition' && trim($text) === '')) {
                continue;
            }
            try {
                $tree = $parser->parse($text);
            } catch (FormulaError $e) {
                $out['errors'][] = ['part' => $part] + $e->toArray();

                continue;
            }
            foreach (Parser::references($tree) as $k => $list) {
                $refs[$k] = array_values(array_unique(array_merge($refs[$k], $list)));
            }
        }
        $out['references'] = $refs;
        if ($out['errors'] !== []) {
            return $out;
        }
        $codes = array_flip($this->catalog->codes());
        foreach ($refs['concept'] as $c) {
            if ($c === $conceptCode) {
                $out['errors'][] = self::err('self_reference', ['name' => $c]);
            } elseif (! isset($codes[$c])) {
                $out['errors'][] = self::err('unknown_concept', ['name' => $c]);
            }
        }
        foreach (array_merge($refs['incident'], $refs['quantity']) as $c) {
            if (! isset($codes[$c]) && $c !== $conceptCode) {
                $out['errors'][] = self::err('unknown_concept', ['name' => $c]);
            }
        }
        foreach ($refs['var'] as $v) {
            if (! ContextVariables::has($v)) {
                $out['errors'][] = self::err('unknown_variable', ['name' => $v]);
            }
        }
        $country = (string) DB::table('current_tenant')->value('country_code');
        foreach ($refs['param'] as $p) {
            $known = DB::table('legal_parameters')->where('country_code', $country)->where('code', $p)->exists()
                || DB::table('legal_parameter_overrides')->where('parameter_code', $p)->exists();
            if (! $known) {
                $out['errors'][] = self::err('unknown_parameter', ['name' => $p]);
            }
        }
        $bases = $this->template->bases($country);
        foreach ($refs['base'] as $b) {
            if (! isset($bases[$b])) {
                $out['errors'][] = self::err('unknown_base', ['name' => $b]);
            }
        }
        if ($out['errors'] !== []) {
            return $out;
        }

        // Ciclo: el grafo de los conceptos vigentes con esta fórmula, condición y afectaciones en lugar de las actuales.
        $all = [];
        foreach ($this->catalog->at($asOf) as $c) {
            $all[$c->code] = $c;
        }
        if ($conceptCode !== null) {
            $current = $all[$conceptCode] ?? null;
            $all[$conceptCode] = new ConceptDefinition($conceptCode, $current === null ? $conceptCode : $current->name, $current === null ? 'earning' : $current->kind,
                $formula, 'half_up', $current === null ? 0 : $current->sortOrder, null, null, null, null, null, $condition,
                $affectations ?? ($current === null ? [] : $current->affectations));
        }
        $deps = [];
        $sort = [];
        foreach ($all as $code => $c) {
            $sort[$code] = $c->sortOrder;
            try {
                $deps[$code] = ConceptDependencies::of($c, $all, $parser)['depends_on'];
            } catch (FormulaError) {
                $deps[$code] = [];
            }
        }
        if ($conceptCode === null) {
            $deps['__formula__'] = ConceptDependencies::expand('__formula__', $refs['concept'], $refs['base'], $all);
            $sort['__formula__'] = PHP_INT_MAX;
        }
        $out['depends_on'] = $deps[$conceptCode ?? '__formula__'];
        try {
            $out['order'] = array_values(array_filter(DependencyGraph::order($deps, $sort), fn ($c) => $c !== '__formula__'));
        } catch (CycleDetected $e) {
            $out['errors'][] = ['code' => 'cycle', 'concepts' => $e->concepts, 'position' => 0,
                'message' => (string) trans('payroll.cycle', ['concepts' => implode(' → ', $e->concepts)])];

            return $out;
        }
        $out['valid'] = true;

        return $out;
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{code: string, message: string, position: int}
     */
    private static function err(string $code, array $replace): array
    {
        return (new FormulaError($code, 0, $replace))->toArray();
    }
}
