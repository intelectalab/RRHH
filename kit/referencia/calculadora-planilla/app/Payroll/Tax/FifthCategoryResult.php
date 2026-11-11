<?php

declare(strict_types=1);

namespace App\Payroll\Tax;

use Brick\Math\BigDecimal;

/** Detalle de la retención de quinta del mes, para la boleta y la auditoría (RN-NOM-04). */
final readonly class FifthCategoryResult
{
    public function __construct(
        public int $month,
        public BigDecimal $projectedAnnualIncome,
        public BigDecimal $deduction,
        public BigDecimal $taxableIncome,
        public BigDecimal $annualTax,
        public BigDecimal $previousWithholdings,
        public int $divisor,
        public BigDecimal $ordinaryWithholding,
        public BigDecimal $extraordinaryWithholding,
        public BigDecimal $withholding,
        public ?BigDecimal $refund = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'projected_annual_income' => (string) $this->projectedAnnualIncome,
            'deduction' => (string) $this->deduction,
            'taxable_income' => (string) $this->taxableIncome,
            'annual_tax' => (string) $this->annualTax,
            'previous_withholdings' => (string) $this->previousWithholdings,
            'divisor' => $this->divisor,
            'ordinary_withholding' => (string) $this->ordinaryWithholding,
            'extraordinary_withholding' => (string) $this->extraordinaryWithholding,
            'withholding' => (string) $this->withholding,
            'refund' => (string) ($this->refund ?? '0.00'),
        ];
    }
}
