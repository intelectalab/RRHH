<?php

declare(strict_types=1);

namespace App\Payroll\Calculation;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** Sueldo nuevo desde una fecha del periodo. Antes del primer cambio rige el sueldo de la ficha. */
final readonly class SalaryChange
{
    public function __construct(
        public DateTimeImmutable $effectiveFrom,
        public BigDecimal $amount,
    ) {}
}
