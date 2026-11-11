<?php

declare(strict_types=1);

namespace App\Payroll\Settlement;

use App\Payroll\Calculation\Line;
use Brick\Math\BigDecimal;

/** Liquidación de beneficios sociales al cese. Los montos van como texto decimal exacto. */
final readonly class SettlementResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    /**
     * @param  list<Line>  $earnings
     * @param  list<Line>  $deductions
     * @param  list<Line>  $employerContributions
     * @param  array<string, array<string, mixed>>  $periods  tramo de cada concepto: desde, hasta, meses y días
     * @param  array<string, array<string, mixed>>  $parametersUsed
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $employeeId,
        public ?string $terminationDate,
        public string $status,
        public ?string $error,
        public BigDecimal $computableRemuneration,
        public BigDecimal $grossTotal,
        public BigDecimal $totalDeductions,
        public BigDecimal $netTotal,
        public array $earnings,
        public array $deductions,
        public array $employerContributions,
        public array $periods,
        public array $parametersUsed,
        public array $warnings,
        public ?array $fifthCategory = null,
    ) {}

    public function amount(string $code): ?string
    {
        foreach ([...$this->earnings, ...$this->deductions, ...$this->employerContributions] as $line) {
            if ($line->code === $code) {
                return (string) $line->amount;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'termination_date' => $this->terminationDate,
            'status' => $this->status,
            'error' => $this->error,
            'computable_remuneration' => (string) $this->computableRemuneration,
            'gross_total' => (string) $this->grossTotal,
            'total_deductions' => (string) $this->totalDeductions,
            'net_total' => (string) $this->netTotal,
            'lines' => [
                'earnings' => array_map(fn (Line $l) => $l->toArray(), $this->earnings),
                'deductions' => array_map(fn (Line $l) => $l->toArray(), $this->deductions),
                'employer' => array_map(fn (Line $l) => $l->toArray(), $this->employerContributions),
            ],
            'periods' => $this->periods,
            'parameters_used' => $this->parametersUsed,
            'warnings' => $this->warnings,
            'fifth_category' => $this->fifthCategory,
        ];
    }
}
