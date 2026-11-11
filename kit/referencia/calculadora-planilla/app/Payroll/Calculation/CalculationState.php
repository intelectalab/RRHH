<?php

declare(strict_types=1);

namespace App\Payroll\Calculation;

use App\Models\Employee;
use App\Payroll\Parameters\ParameterRepository;
use App\Payroll\Parameters\ParameterValue;
use App\Payroll\Parameters\PensionFundRates;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Estado que recorren los pasos de la calculadora. Cada paso lee lo que dejaron los anteriores y
 * agrega sus líneas. Toda tasa o monto legal se pide aquí, que registra qué valor y vigencia se usó.
 */
final class CalculationState
{
    /** @var array<string, Line> */
    public array $earnings = [];

    /** @var array<string, Line> */
    public array $deductions = [];

    /** @var array<string, Line> */
    public array $employerContributions = [];

    /** @var array<string, Line> */
    public array $provisions = [];

    /** @var array<string, ParameterValue> */
    private array $used = [];

    /** @var list<string> */
    public array $warnings = [];

    /** Días pagados del mes (dias_base_mes si el mes es completo). Lo fija BaseSalaryStep. */
    public ?int $workedDays = null;

    /** Sueldo mensual completo vigente al cierre del mes, para proyectar la quinta. */
    public ?BigDecimal $monthlySalary = null;

    /** Jornal básico del día (construcción civil). Lo fija ConstructionWageStep. */
    public ?BigDecimal $dailyWage = null;

    /** @var array<string, mixed>|null detalle de la proyección de quinta */
    public ?array $fifthCategory = null;

    public function __construct(
        public readonly Employee $employee,
        public readonly PayrollContext $context,
        private readonly ParameterRepository $parameters,
        private readonly int $scale,
    ) {}

    /** El colaborador cesa dentro de este periodo. */
    public function terminatesInPeriod(): bool
    {
        $end = $this->employee->termination_date;

        return $end !== null && $end <= $this->context->periodDate;
    }

    public function regime(): string
    {
        return $this->employee->labor_regime_code;
    }

    /** Parámetro del régimen del colaborador (o el global si el régimen no tiene uno propio). */
    public function param(string $code): ParameterValue
    {
        return $this->track($this->parameters->get($code, $this->regime(), $this->context->periodDate));
    }

    /** Parámetro global del país, sin mirar el régimen. */
    public function globalParam(string $code): ParameterValue
    {
        return $this->track($this->parameters->get($code, null, $this->context->periodDate));
    }

    /** Indicador 1/0 del régimen, por ejemplo «gratificacion_aplica». */
    public function flag(string $code): bool
    {
        return ! $this->param($code)->required()->isZero();
    }

    public function pensionFundRates(string $fundCode): PensionFundRates
    {
        $rates = $this->parameters->pensionFundRates($fundCode, $this->context->periodDate);
        if (! $rates->verified) {
            $this->warnings[] = "Las tasas de {$fundCode} vigentes desde {$rates->validFrom} están sin confirmar.";
        }

        return $rates;
    }

    public function money(BigDecimal $value): BigDecimal
    {
        return $value->toScale($this->scale, RoundingMode::HALF_UP);
    }

    public function addEarning(Line $line): void
    {
        $this->earnings[$line->code] = $line;
    }

    public function addDeduction(Line $line): void
    {
        $this->deductions[$line->code] = $line;
    }

    public function addEmployerContribution(Line $line): void
    {
        $this->employerContributions[$line->code] = $line;
    }

    public function addProvision(Line $line): void
    {
        $this->provisions[$line->code] = $line;
    }

    /** Base de pensión, EsSalud y quinta: ingresos remunerativos del mes. */
    public function remunerativeBase(): BigDecimal
    {
        return $this->sum(array_filter($this->earnings, fn (Line $l) => $l->remunerative));
    }

    /** Remuneración computable para gratificación y CTS: pagos regulares del mes. */
    public function computableBase(): BigDecimal
    {
        return $this->sum(array_filter($this->earnings, fn (Line $l) => $l->computable));
    }

    /** @param array<array-key, Line> $lines */
    public function sum(array $lines): BigDecimal
    {
        return array_reduce($lines, fn (BigDecimal $c, Line $l) => $c->plus($l->amount), BigDecimal::zero())->toScale($this->scale);
    }

    /** @return array<string, ParameterValue> */
    public function usedParameters(): array
    {
        return $this->used;
    }

    private function track(ParameterValue $p): ParameterValue
    {
        $this->used[$p->code.($p->regimeCode ? "@{$p->regimeCode}" : '')] = $p;
        if (! $p->verified) {
            // Se puede simular, pero el cierre del periodo exige confirmación (RN-PAR-03).
            $this->warnings[] = "El parámetro «{$p->code}» está sin confirmar.";
        }

        return $p;
    }
}
