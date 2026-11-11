<?php

declare(strict_types=1);

namespace App\Payroll\Parameters;

use Brick\Math\BigDecimal;

/** Tasas de una AFP vigentes en el periodo, en porcentaje (10 = 10 %). */
final readonly class PensionFundRates
{
    public function __construct(
        public string $fundCode,
        public BigDecimal $contributionRate,
        public ?BigDecimal $commissionFlowRate,
        public ?BigDecimal $commissionMixedRate,
        public BigDecimal $insurancePremiumRate,
        public BigDecimal $maxInsurableRemuneration,
        public bool $verified,
        public string $validFrom,
    ) {}
}
