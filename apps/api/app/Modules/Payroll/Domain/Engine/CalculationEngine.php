<?php

namespace App\Modules\Payroll\Domain\Engine;

use App\Modules\Payroll\Domain\Formula\Evaluator;
use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\Parser;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * NOM-1 · Núcleo genérico del motor de nómina: ejecuta conceptos y fórmulas sobre un contexto. No conoce
 * ningún país ni régimen (RN-NOM-12): los conceptos aplicables, los parámetros y el redondeo llegan de fuera.
 *
 * Mismas entradas → mismo resultado (RN-NOM-02): no lee el reloj ni depende del orden en que llegan los
 * conceptos. Cada línea guarda su traza: fórmula, datos, parámetros con su vigencia y conceptos usados
 * (RN-NOM-04). Una relación con datos obligatorios faltantes no se calcula y no detiene a las demás (RN-NOM-10).
 */
final class CalculationEngine
{
    public const VERSION = 'nom-core-1';

    /**
     * @param  list<ConceptDefinition>  $concepts
     * @param  int  $decimals  decimales del redondeo final, del paquete de país (RN-NOM-08)
     * @return array{status: string, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, order: list<string>, lines: list<array<string, mixed>>, totals: array<string, string>, engine_version: string}
     */
    public function run(CalculationContext $context, array $concepts, ParameterResolver $parameters, int $decimals): array
    {
        $result = ['status' => 'error', 'errors' => [], 'warnings' => [], 'order' => [], 'lines' => [], 'totals' => [], 'engine_version' => self::VERSION];
        if ($context->errors !== []) {
            $result['errors'] = $context->errors;

            return $result;
        }

        $applicable = [];
        foreach ($concepts as $c) {
            if ($c->appliesTo($context->regime, $context->periodKind)) {
                $applicable[$c->code] = $c;
            }
        }
        $parser = new Parser;
        $trees = [];
        $deps = [];
        $sort = [];
        foreach ($applicable as $code => $c) {
            $sort[$code] = $c->sortOrder;
            if ($c->formula === null || trim($c->formula) === '') {
                $deps[$code] = [];

                continue;
            }
            try {
                $trees[$code] = $parser->parse($c->formula);
            } catch (FormulaError $e) {
                $result['errors'][] = ['code' => 'formula_invalid', 'concept' => $code] + $e->toArray();

                continue;
            }
            $deps[$code] = Parser::references($trees[$code])['concept'];
        }
        if ($result['errors'] !== []) {
            return $result;
        }
        try {
            $order = DependencyGraph::order($deps, $sort);
        } catch (CycleDetected $e) {
            $result['errors'][] = ['code' => 'cycle', 'concepts' => $e->concepts, 'message' => (string) trans('payroll.cycle', ['concepts' => implode(' → ', $e->concepts)])];

            return $result;
        }
        $result['order'] = $order;

        $scope = new EngineScope($context, $parameters, $applicable);
        $evaluator = new Evaluator($scope);
        foreach ($order as $code) {
            $c = $applicable[$code];
            $scope->startTrace();
            try {
                $raw = isset($trees[$code]) ? $evaluator->number($trees[$code]) : $scope->incident($code, 'amount');
            } catch (FormulaError $e) {
                $result['errors'][] = ['code' => 'evaluation_failed', 'concept' => $code] + $e->toArray();

                continue;
            }
            $amount = self::round($raw, $c->rounding, $decimals);
            // Los demás conceptos usan el monto ya redondeado: la boleta se explica sumando sus líneas.
            $scope->setResult($code, $amount);
            $trace = $scope->trace();
            foreach ($trace['parameters'] as $p) {
                if ($p['pending_validation']) {
                    $result['warnings'][$p['code']] = ['code' => 'parameter_pending_validation', 'parameter' => $p['code'],
                        'message' => (string) trans('payroll.parameter_pending', ['code' => $p['code']])];
                }
            }
            $result['lines'][] = [
                'concept_code' => $code, 'concept_name' => $c->name, 'kind' => $c->kind,
                'concept_id' => $c->conceptId, 'concept_version_id' => $c->versionId,
                'amount' => ['amount' => (string) $amount, 'currency' => $context->currency],
                'trace' => ['formula' => $c->formula, 'source' => isset($trees[$code]) ? 'formula' : 'incidents', 'raw' => (string) $raw,
                    'rounding' => $c->rounding, 'decimals' => $decimals, 'version_valid_from' => $c->validFrom] + $trace,
            ];
        }
        $result['warnings'] = array_values($result['warnings']);
        if ($result['errors'] !== []) {
            $result['lines'] = [];

            return $result;
        }
        $result['status'] = 'calculated';
        $result['totals'] = self::totals($result['lines'], $decimals);

        return $result;
    }

    public static function round(BigDecimal $v, string $mode, int $decimals): BigDecimal
    {
        return match ($mode) {
            'none' => $v->toScale($decimals, RoundingMode::HALF_UP),
            'down' => $v->toScale($decimals, RoundingMode::DOWN),
            'up' => $v->toScale($decimals, RoundingMode::UP),
            default => $v->toScale($decimals, RoundingMode::HALF_UP),
        };
    }

    /**
     * Totales por naturaleza del concepto (sin ninguna regla legal): ingresos, descuentos al trabajador
     * (descuentos, aportes del trabajador e impuestos), neto y aportes del empleador.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, string>
     */
    public static function totals(array $lines, int $decimals): array
    {
        $zero = BigDecimal::zero()->toScale($decimals);
        $t = ['earnings' => $zero, 'deductions' => $zero, 'employer_contributions' => $zero];
        foreach ($lines as $l) {
            $a = BigDecimal::of($l['amount']['amount']);
            $key = match ($l['kind']) {
                'earning', 'benefit' => 'earnings',
                'deduction', 'employee_contribution', 'tax' => 'deductions',
                'employer_contribution' => 'employer_contributions',
                default => null,
            };
            if ($key !== null) {
                $t[$key] = $t[$key]->plus($a);
            }
        }
        $net = $t['earnings']->minus($t['deductions']);

        return ['earnings' => (string) $t['earnings'], 'deductions' => (string) $t['deductions'], 'net' => (string) $net,
            'employer_contributions' => (string) $t['employer_contributions'], 'employer_cost' => (string) $t['earnings']->plus($t['employer_contributions'])];
    }
}
