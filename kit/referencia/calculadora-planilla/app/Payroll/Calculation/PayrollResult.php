<?php

declare(strict_types=1);

namespace App\Payroll\Calculation;

use Brick\Math\BigDecimal;

/** Resultado del cálculo mensual de un colaborador. Los montos van como texto decimal exacto. */
final readonly class PayrollResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    /**
     * @param  array<string, BigDecimal>  $provisions
     * @param  list<Line>  $earnings
     * @param  list<Line>  $deductions
     * @param  list<Line>  $employerContributions
     * @param  array<string, array<string, mixed>>  $parametersUsed
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $employeeId,
        public string $period,
        public string $status,
        public ?string $error,
        public BigDecimal $grossSalary,
        public BigDecimal $familyAllowance,
        public BigDecimal $pensionDeduction,
        public BigDecimal $totalDeductions,
        public BigDecimal $netSalary,
        public BigDecimal $essaludContribution,
        public array $provisions,
        public BigDecimal $employerCost,
        public array $earnings,
        public array $deductions,
        public array $employerContributions,
        public array $parametersUsed,
        public array $warnings,
        public ?int $workedDays = null,
        public ?array $fifthCategory = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'period' => $this->period,
            'status' => $this->status,
            'error' => $this->error,
            'gross_salary' => (string) $this->grossSalary,
            'family_allowance' => (string) $this->familyAllowance,
            'pension_deduction' => (string) $this->pensionDeduction,
            'total_deductions' => (string) $this->totalDeductions,
            'net_salary' => (string) $this->netSalary,
            'essalud_contribution' => (string) $this->essaludContribution,
            'provisions' => array_map('strval', $this->provisions),
            'employer_cost' => (string) $this->employerCost,
            'lines' => [
                'earnings' => array_map(fn (Line $l) => $l->toArray(), $this->earnings),
                'deductions' => array_map(fn (Line $l) => $l->toArray(), $this->deductions),
                'employer' => array_map(fn (Line $l) => $l->toArray(), $this->employerContributions),
            ],
            'parameters_used' => $this->parametersUsed,
            'worked_days' => $this->workedDays,
            'fifth_category' => $this->fifthCategory,
            'warnings' => $this->warnings,
        ];
    }
}
