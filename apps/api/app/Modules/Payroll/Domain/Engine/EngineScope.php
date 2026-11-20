<?php

namespace App\Modules\Payroll\Domain\Engine;

use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\FormulaScope;
use Brick\Math\BigDecimal;

/**
 * @internal Alcance de evaluación de una corrida: lo que la fórmula puede leer y lo que usó (traza).
 */
final class EngineScope implements FormulaScope
{
    /** @var array<string, BigDecimal> */
    private array $results = [];

    /** @var array{variables: array<string, mixed>, parameters: array<string, array<string, mixed>>, concepts: array<string, string>, incidents: array<string, array<string, string>>, bases: array<string, array{amount: string, concepts: array<string, string>}>} */
    private array $trace = ['variables' => [], 'parameters' => [], 'concepts' => [], 'incidents' => [], 'bases' => []];

    /** @var array<string, array<string, mixed>|null> */
    private array $paramCache = [];

    /**
     * @param  array<string, ConceptDefinition>  $concepts
     */
    public function __construct(private readonly CalculationContext $context, private readonly ParameterResolver $parameters, private readonly array $concepts) {}

    public function startTrace(): void
    {
        $this->trace = ['variables' => [], 'parameters' => [], 'concepts' => [], 'incidents' => [], 'bases' => []];
    }

    /** @return array{variables: array<string, mixed>, parameters: list<array<string, mixed>>, concepts: array<string, string>, incidents: array<string, array<string, string>>, bases: array<string, array{amount: string, concepts: array<string, string>}>} */
    public function trace(): array
    {
        return ['variables' => $this->trace['variables'], 'parameters' => array_values($this->trace['parameters']),
            'concepts' => $this->trace['concepts'], 'incidents' => $this->trace['incidents'], 'bases' => $this->trace['bases']];
    }

    public function setResult(string $code, BigDecimal $value): void
    {
        $this->results[$code] = $value;
    }

    public function variable(string $name): BigDecimal|string|bool
    {
        if (! array_key_exists($name, $this->context->variables)) {
            throw new FormulaError('unknown_variable', 0, ['name' => $name]);
        }
        $v = $this->context->variables[$name];
        $this->trace['variables'][$name] = $v instanceof BigDecimal ? (string) $v : $v;

        return $v;
    }

    public function concept(string $code): BigDecimal
    {
        if (! isset($this->results[$code])) {
            // Un concepto que no aplica a este régimen o tipo de proceso vale cero para los demás.
            if (! isset($this->concepts[$code])) {
                $this->trace['concepts'][$code] = '0';

                return BigDecimal::zero();
            }
            throw new FormulaError('unknown_concept', 0, ['name' => $code]);
        }
        $this->trace['concepts'][$code] = (string) $this->results[$code];

        return $this->results[$code];
    }

    public function parameter(string $code): BigDecimal
    {
        $p = $this->paramCache[$code] ??= $this->parameters->resolve($code);
        if ($p === null) {
            throw new FormulaError('unknown_parameter', 0, ['name' => $code]);
        }
        if ($p['value'] === null || ! is_numeric($p['value'])) {
            throw new FormulaError($p['value'] === null ? 'parameter_without_value' : 'parameter_not_numeric', 0, ['name' => $code]);
        }
        $this->trace['parameters'][$code] = $p;

        return BigDecimal::of($p['value']);
    }

    public function incident(string $code, string $field): BigDecimal
    {
        $row = $this->context->incidents[$code] ?? ['amount' => '0', 'quantity' => '0'];
        $this->trace['incidents'][$code] = $row;

        return BigDecimal::of($field === 'quantity' ? $row['quantity'] : $row['amount']);
    }

    /**
     * CPT-3 · Suma de los conceptos ya calculados que afectan a la base; la traza dice cuáles la formaron.
     * El orden de cálculo garantiza que todos ellos se calcularon antes (ConceptDependencies).
     */
    public function base(string $code): BigDecimal
    {
        $sum = BigDecimal::zero();
        $parts = [];
        foreach ($this->concepts as $c) {
            if ($c->affects($code) && isset($this->results[$c->code])) {
                $sum = $sum->plus($this->results[$c->code]);
                $parts[$c->code] = (string) $this->results[$c->code];
            }
        }
        $this->trace['bases'][$code] = ['amount' => (string) $sum, 'concepts' => $parts];

        return $sum;
    }
}
