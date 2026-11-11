<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\Calculation\SalaryChange;
use App\Payroll\Calculation\ServiceTime;
use App\Payroll\PayrollException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Remuneración básica del mes, prorrateada por ingreso, cese o cambio de sueldo (RN-NOM-05).
 *
 *   cada tramo = sueldo del tramo ÷ dias_base_mes × días calendario del tramo
 *
 * El mes completo cuenta dias_base_mes días aunque tenga 28 o 31; si hubo cambio de sueldo en un mes
 * completo, el último tramo absorbe la diferencia. En un mes incompleto se pagan los días calendario,
 * sin pasar de dias_base_mes [validar con el especialista].
 */
final class BaseSalaryStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $employee = $state->employee;
        $context = $state->context;
        $monthStart = $context->monthStart();
        $monthEnd = $context->periodDate;

        $from = $employee->hire_date !== null && $employee->hire_date > $monthStart ? $employee->hire_date : $monthStart;
        $to = $employee->termination_date !== null && $employee->termination_date < $monthEnd ? $employee->termination_date : $monthEnd;
        if ($from > $to) {
            throw PayrollException::notInPeriod((string) $employee->full_name, $monthEnd->format('Y-m'));
        }

        $baseDays = $state->globalParam('dias_base_mes')->required()->toInt();
        $fullMonth = $from == $monthStart && $to == $monthEnd;

        $segments = $this->segments(BigDecimal::of($employee->base_salary), $context->salaryChanges, $from, $to);
        $paidDays = 0;
        $amount = BigDecimal::zero();
        foreach ($segments as $i => [$salary, $days]) {
            $isLast = $i === array_key_last($segments);
            if ($isLast) {
                $days = $fullMonth ? $baseDays - $paidDays : min($days, $baseDays - $paidDays);
            }
            $paidDays += $days;
            $amount = $amount->plus($salary->multipliedBy($days)->dividedBy($baseDays, 10, RoundingMode::HALF_UP));
        }

        $state->workedDays = $paidDays;
        $state->monthlySalary = $segments[array_key_last($segments)][0];
        $label = $paidDays === $baseDays && count($segments) === 1 ? 'Remuneración básica' : "Remuneración básica ({$paidDays} días)";
        $state->addEarning(new Line('rem_basica', $label, $state->money($amount)));
    }

    /**
     * Tramos de sueldo entre dos fechas: [sueldo, días calendario].
     *
     * @param  list<SalaryChange>  $changes
     * @return list<array{0: BigDecimal, 1: int}>
     */
    private function segments(BigDecimal $initial, array $changes, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        usort($changes, fn (SalaryChange $a, SalaryChange $b) => $a->effectiveFrom <=> $b->effectiveFrom);

        $salary = $initial;
        $cursor = $from;
        $segments = [];
        foreach ($changes as $change) {
            if ($change->effectiveFrom <= $from) {
                $salary = $change->amount;   // el cambio ya regía al empezar el tramo pagado

                continue;
            }
            if ($change->effectiveFrom > $to) {
                break;
            }
            $segments[] = [$salary, ServiceTime::days($cursor, $change->effectiveFrom->modify('-1 day'))];
            $salary = $change->amount;
            $cursor = $change->effectiveFrom;
        }
        $segments[] = [$salary, ServiceTime::days($cursor, $to)];

        return $segments;
    }
}
