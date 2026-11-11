<?php

declare(strict_types=1);

namespace App\Payroll\Settlement;

use App\Payroll\Tax\FifthCategoryHistory;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Datos de la liquidación que no están en la ficha. En el producto los arma el módulo con los periodos
 * cerrados, los depósitos de CTS y el récord vacacional.
 */
final readonly class SettlementContext
{
    /**
     * @param  ?DateTimeImmutable  $terminationDate  último día laborado; si es null se usa el de la ficha
     * @param  BigDecimal  $variableComputable  promedio de las remuneraciones variables regulares que entran en la computable
     * @param  BigDecimal  $gratificationInCtsPeriod  gratificación cobrada dentro del periodo de CTS trunca (entra un sexto)
     * @param  int  $pendingVacationDays  días de vacaciones ganados y no gozados de años completos
     * @param  ?DateTimeImmutable  $ctsPeriodStart  inicio del periodo sin depositar; si es null, el mes del último depósito (cts_meses_deposito)
     * @param  ?DateTimeImmutable  $vacationPeriodStart  inicio del año de servicios incompleto; si es null, el último aniversario de ingreso
     * @param  ?Dismissal  $dismissal  despido arbitrario que da indemnización; null si el cese es por otra causa
     * @param  int  $expiredVacationDays  días de vacaciones de periodos que no se gozaron en el año siguiente (indemnización vacacional)
     * @param  ?FifthCategoryHistory  $fifthCategoryHistory  con él se calcula la retención de quinta del cese sobre la liquidación
     * @param  ?BigDecimal  $lastMonthFifthIncome  rentas de quinta de la planilla del mes del cese, que se suman a las de la liquidación
     * @param  ?BigDecimal  $lastMonthWithholding  retención de quinta ya hecha en la planilla del mes del cese
     */
    public function __construct(
        public ?DateTimeImmutable $terminationDate = null,
        public ?BigDecimal $variableComputable = null,
        public ?BigDecimal $gratificationInCtsPeriod = null,
        public int $pendingVacationDays = 0,
        public ?DateTimeImmutable $ctsPeriodStart = null,
        public ?DateTimeImmutable $vacationPeriodStart = null,
        public ?Dismissal $dismissal = null,
        public int $expiredVacationDays = 0,
        public ?FifthCategoryHistory $fifthCategoryHistory = null,
        public ?BigDecimal $lastMonthFifthIncome = null,
        public ?BigDecimal $lastMonthWithholding = null,
    ) {}
}
