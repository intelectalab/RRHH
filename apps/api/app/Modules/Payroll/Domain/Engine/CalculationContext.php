<?php

namespace App\Modules\Payroll\Domain\Engine;

use Brick\Math\BigDecimal;

/**
 * NOM-5 · Lo que el motor sabe de una relación laboral en un periodo: datos (variables de las fórmulas),
 * tramos de vigencia, incidencias y errores de datos obligatorios (RN-NOM-10). Se guarda tal cual en la
 * corrida para auditoría (RN-NOM-04).
 */
final class CalculationContext
{
    /**
     * @param  array<string, BigDecimal|string|bool>  $variables
     * @param  array<string, array{amount: string, quantity: string}>  $incidents  por código de concepto
     * @param  list<array<string, mixed>>  $segments
     * @param  list<array{code: string, message: string}>  $errors
     * @param  array<string, mixed>  $sources  huellas y orígenes de las entradas (ausencias, etc.)
     */
    public function __construct(
        public readonly string $employmentId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $periodKind,
        public readonly string $currency,
        public readonly ?string $regime,
        public readonly array $variables,
        public readonly array $incidents = [],
        public readonly array $segments = [],
        public readonly array $errors = [],
        public readonly array $sources = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $vars = [];
        foreach ($this->variables as $k => $v) {
            $vars[$k] = $v instanceof BigDecimal ? (string) $v : $v;
        }
        ksort($vars);

        return [
            'employment_id' => $this->employmentId, 'from' => $this->from, 'to' => $this->to, 'period_kind' => $this->periodKind,
            'currency' => $this->currency, 'regime' => $this->regime, 'variables' => $vars, 'incidents' => $this->incidents,
            'segments' => $this->segments, 'errors' => $this->errors, 'sources' => $this->sources,
        ];
    }
}
