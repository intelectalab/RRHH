<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\Calculation\PayrollContext;
use App\Payroll\Calculation\PayrollResult;
use App\Payroll\Parameters\ParameterRepository;
use App\Payroll\Parameters\ParameterValue;
use App\Payroll\PayrollException;
use App\Payroll\Steps\PayrollStep;
use Brick\Math\BigDecimal;

/**
 * Calculadora mensual de planilla de un colaborador.
 *
 * Recorre los pasos configurados en config/payroll.php. Si falta un parámetro, el resultado queda con
 * estado «error» y un mensaje claro, sin lanzar excepción, para que el resto de la planilla se calcule
 * (RN-NOM-10). El mismo colaborador con las mismas entradas da siempre el mismo resultado (RN-NOM-02).
 */
final class PayrollService
{
    /**
     * @param  iterable<PayrollStep>  $steps  pasos del régimen general y de los que no tienen pasos propios
     * @param  array<string, iterable<PayrollStep>>  $stepsByRegime  pasos propios de un régimen (agrario, construcción civil)
     */
    public function __construct(
        private readonly ParameterRepository $parameters,
        private readonly iterable $steps,
        private readonly int $scale = 2,
        private readonly array $stepsByRegime = [],
    ) {}

    public function calculate(Employee $employee, ?PayrollContext $context = null): PayrollResult
    {
        $context ??= PayrollContext::forMonth((int) date('Y'), (int) date('n'));
        $state = new CalculationState($employee, $context, $this->parameters, $this->scale);

        $error = null;
        try {
            foreach ($this->stepsByRegime[$employee->labor_regime_code] ?? $this->steps as $step) {
                $step->apply($state);
            }
        } catch (PayrollException $e) {
            $error = $e->getMessage();
        }

        return $this->result($state, $error);
    }

    private function result(CalculationState $s, ?string $error): PayrollResult
    {
        $pick = fn (array $lines, array $codes) => $s->sum(array_intersect_key($lines, array_flip($codes)));

        $gross = $s->sum($s->earnings);
        $deductions = $s->sum($s->deductions);
        $employer = $s->sum($s->employerContributions);
        $provisions = array_map(fn (Line $l) => $l->amount, $s->provisions);

        return new PayrollResult(
            employeeId: (string) $s->employee->getKey(),
            period: $s->context->periodDate->format('Y-m'),
            status: $error === null ? PayrollResult::STATUS_OK : PayrollResult::STATUS_ERROR,
            error: $error,
            grossSalary: $gross,
            familyAllowance: $pick($s->earnings, ['asig_familiar']),
            pensionDeduction: $pick($s->deductions, ['onp_aporte', 'afp_aporte', 'afp_comision', 'afp_prima']),
            totalDeductions: $deductions,
            netSalary: $gross->minus($deductions),
            essaludContribution: $pick($s->employerContributions, ['essalud', 'sis_mype']),
            provisions: $provisions,
            employerCost: $gross->plus($employer)->plus(array_reduce($provisions, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero())),
            earnings: array_values($s->earnings),
            deductions: array_values($s->deductions),
            employerContributions: array_values($s->employerContributions),
            parametersUsed: array_map(fn (ParameterValue $p) => $p->toArray(), $s->usedParameters()),
            warnings: array_values(array_unique($s->warnings)),
            workedDays: $s->workedDays,
            fifthCategory: $s->fifthCategory,
        );
    }
}
