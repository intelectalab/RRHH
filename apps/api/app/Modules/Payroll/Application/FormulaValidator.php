<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Engine\ContextVariables;
use App\Modules\Payroll\Domain\Engine\CycleDetected;
use App\Modules\Payroll\Domain\Engine\DependencyGraph;
use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\Parser;
use App\Modules\Payroll\Infrastructure\ConceptCatalog;
use Illuminate\Support\Facades\DB;

/**
 * NOM-3 · NOM-4 · RN-NOM-06 · Revisión de una fórmula antes de guardarla o publicarla: sintaxis, funciones,
 * datos, conceptos e incidencias que existen, parámetros del catálogo legal del país y que no forme un ciclo
 * con los conceptos vigentes. M-CPT (editor de conceptos) la usa para no guardar una fórmula inválida.
 */
final class FormulaValidator
{
    public function __construct(private readonly ConceptCatalog $catalog) {}

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>, references: array<string, list<string>>, order: list<string>}
     */
    public function validate(string $formula, ?string $conceptCode, string $asOf): array
    {
        $out = ['valid' => false, 'errors' => [], 'references' => ['concept' => [], 'param' => [], 'incident' => [], 'quantity' => [], 'var' => []], 'order' => []];
        try {
            $tree = (new Parser)->parse($formula);
        } catch (FormulaError $e) {
            $out['errors'][] = $e->toArray();

            return $out;
        }
        $refs = Parser::references($tree);
        $out['references'] = $refs;
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
        if ($out['errors'] !== []) {
            return $out;
        }

        // Ciclo: el grafo de los conceptos vigentes con esta fórmula en lugar de la actual del concepto.
        $deps = [];
        $sort = [];
        $parser = new Parser;
        foreach ($this->catalog->at($asOf) as $c) {
            $sort[$c->code] = $c->sortOrder;
            try {
                $deps[$c->code] = $c->formula === null || trim($c->formula) === '' ? [] : Parser::references($parser->parse($c->formula))['concept'];
            } catch (FormulaError) {
                $deps[$c->code] = [];
            }
        }
        if ($conceptCode !== null) {
            $deps[$conceptCode] = $refs['concept'];
        }
        try {
            $out['order'] = DependencyGraph::order($deps, $sort);
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
