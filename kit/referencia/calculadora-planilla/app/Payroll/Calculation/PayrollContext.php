<?php

declare(strict_types=1);

namespace App\Payroll\Calculation;

use App\Payroll\Tax\FifthCategoryHistory;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Datos del periodo que no son parte de la ficha: horas extras, bonificaciones, otros descuentos, cambios
 * de sueldo y lo que la quinta necesita del resto del año. Agregar un campo aquí no cambia la firma de
 * PayrollService::calculate().
 */
final readonly class PayrollContext
{
    /**
     * @param  list<Line>  $earnings  ingresos adicionales del periodo (horas extras, bonificaciones…)
     * @param  list<Line>  $deductions  descuentos adicionales (préstamos, adelantos, retenciones judiciales…)
     * @param  list<SalaryChange>  $salaryChanges  cambios de sueldo dentro del mes (RN-NOM-05)
     * @param  ?BigDecimal  $fifthCategoryWithholding  retención de quinta ya calculada por otro sistema; gana sobre la proyección
     * @param  ?FifthCategoryHistory  $fifthCategoryHistory  con él, la calculadora proyecta la quinta del mes
     * @param  ?int  $constructionPaidDays  construcción civil: jornales pagados en el periodo (incluye dominicales y feriados)
     * @param  ?int  $constructionWorkedDays  construcción civil: días efectivamente laborados (movilidad)
     */
    public function __construct(
        public DateTimeImmutable $periodDate,
        public array $earnings = [],
        public array $deductions = [],
        public ?BigDecimal $fifthCategoryWithholding = null,
        public array $salaryChanges = [],
        public ?FifthCategoryHistory $fifthCategoryHistory = null,
        public ?int $constructionPaidDays = null,
        public ?int $constructionWorkedDays = null,
    ) {}

    /** Periodo del mes de la fecha dada; los parámetros se toman vigentes al último día (RN-NOM-01). */
    public static function forMonth(int $year, int $month, mixed ...$rest): self
    {
        return new self((new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month'), ...$rest);
    }

    public function monthStart(): DateTimeImmutable
    {
        return $this->periodDate->modify('first day of this month');
    }
}
