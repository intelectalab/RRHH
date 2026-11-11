<?php

declare(strict_types=1);

namespace App\Payroll\Calculation;

use Brick\Math\BigDecimal;

/**
 * Una línea de la boleta o del costo del empleador.
 *
 * - remunerative: entra en la base de pensión, EsSalud y quinta categoría.
 * - computable: entra en la remuneración computable de gratificación y CTS (pagos regulares).
 * - fifthCategory: entra en la renta de quinta. Si no se indica, sigue a «remunerative»; la gratificación
 *   y su bonificación extraordinaria no son remunerativas para aportes, pero sí pagan quinta.
 * - extraordinary: pago no habitual (bono, participación, reintegro): su quinta se retiene completa en el mes.
 */
final readonly class Line
{
    public function __construct(
        public string $code,
        public string $label,
        public BigDecimal $amount,
        public bool $remunerative = true,
        public bool $computable = true,
        public ?bool $fifthCategory = null,
        public bool $extraordinary = false,
    ) {}

    public function isFifthCategoryIncome(): bool
    {
        return $this->fifthCategory ?? $this->remunerative;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'amount' => (string) $this->amount];
    }
}
