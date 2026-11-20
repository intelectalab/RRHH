<?php

namespace App\Modules\Payroll\Domain\Formula;

/**
 * NOM-3 · Error de una fórmula: de sintaxis o de referencia (al guardar) o de evaluación (al calcular).
 * La posición es el desplazamiento (desde 0) del símbolo que lo provocó; la clave de mensaje se traduce
 * con payroll.formula.* y sus variables.
 */
final class FormulaError extends \RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(public readonly string $key, public readonly int $position = 0, public readonly array $replace = [])
    {
        parent::__construct($key);
    }

    /**
     * @return array{code: string, message: string, position: int}
     */
    public function toArray(): array
    {
        return ['code' => $this->key, 'message' => (string) trans('payroll.formula.'.$this->key, $this->replace), 'position' => $this->position];
    }
}
