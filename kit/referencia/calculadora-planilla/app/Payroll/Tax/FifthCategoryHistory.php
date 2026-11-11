<?php

declare(strict_types=1);

namespace App\Payroll\Tax;

use Brick\Math\BigDecimal;

/**
 * Lo que la proyección de quinta necesita del resto del año (RN-NOM-13 a RN-NOM-16). En el producto lo
 * arma el módulo de planilla con los periodos cerrados y las rentas de otros empleadores declaradas.
 */
final readonly class FifthCategoryHistory
{
    /**
     * @param  BigDecimal  $previousIncome  rentas de quinta cobradas en los meses anteriores del año, de este y de otros empleadores
     * @param  array<int, BigDecimal>  $withholdingsByMonth  retenciones de quinta ya hechas, por número de mes (1 a 12)
     * @param  BigDecimal  $pendingGratifications  gratificaciones ordinarias por cobrar en el año, con su bonificación extraordinaria
     */
    public function __construct(
        public BigDecimal $previousIncome,
        public array $withholdingsByMonth = [],
        public ?BigDecimal $pendingGratifications = null,
    ) {}
}
