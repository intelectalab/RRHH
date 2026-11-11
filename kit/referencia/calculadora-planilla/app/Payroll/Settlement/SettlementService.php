<?php

declare(strict_types=1);

namespace App\Payroll\Settlement;

use App\Models\Employee;
use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\Calculation\PayrollContext;
use App\Payroll\Calculation\ServiceTime;
use App\Payroll\Parameters\ParameterRepository;
use App\Payroll\Parameters\ParameterValue;
use App\Payroll\PayrollException;
use App\Payroll\Steps\FamilyAllowanceStep;
use App\Payroll\Steps\HealthContributionStep;
use App\Payroll\Steps\PensionStep;
use App\Payroll\Tax\FifthCategoryCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

/**
 * Liquidación de beneficios sociales al cese (gratificación, CTS y vacaciones truncas y pendientes).
 *
 *   remuneración computable (RC) = sueldo + asignación familiar + promedio de variables regulares
 *   gratificación trunca  = RC × gratificacion_factor ÷ 6 × meses calendario completos del semestre
 *                           + bonificación extraordinaria (Ley 30334)
 *   CTS trunca            = (RC + gratificación del periodo × cts_fraccion_gratificacion)
 *                           × (meses ÷ 12 + días ÷ (12 × dias_base_mes)) × proporción del régimen
 *   vacaciones truncas    = RC × (vacaciones_dias ÷ dias_base_mes) × (meses ÷ 12 + días ÷ (12 × dias_base_mes))
 *   vacaciones pendientes = RC ÷ dias_base_mes × días ganados y no gozados
 *   indemnización vacacional = RC ÷ dias_base_mes × días de periodos vencidos sin gozar (DL 713 art. 23)
 *   indemnización por despido (DS 003-97-TR arts. 38 y 76), en remuneraciones diarias (RC ÷ dias_base_mes):
 *       plazo indeterminado: indemnizacion_dias_por_anio × (años + meses ÷ 12 + días ÷ 360)
 *       plazo fijo: indemnizacion_plazo_fijo_dias_por_mes × (meses + días ÷ dias_base_mes) que faltaban
 *       con el tope indemnizacion_tope_dias; cada régimen trae sus propios días y tope
 *   quinta del cese: impuesto anual sobre lo realmente cobrado en el año, sin proyectar meses ni
 *       gratificaciones futuras, menos todo lo retenido; si lo retenido supera el impuesto, hay exceso
 *
 * Las vacaciones son remunerativas: sobre ellas se calcula el aporte a pensión y a EsSalud con los mismos
 * pasos de la planilla mensual. La gratificación y la CTS no llevan aportes. La remuneración del último
 * mes, prorrateada, va en la planilla del mes (PayrollService), no aquí.
 *
 * Las indemnizaciones no llevan aportes ni pagan quinta dentro del monto legal [validar: LIR art. 18].
 */
final class SettlementService
{
    private const MONTHS_PER_SEMESTER = 6;

    private const MONTHS_PER_YEAR = 12;

    public function __construct(
        private readonly ParameterRepository $parameters,
        private readonly int $scale = 2,
    ) {}

    public function calculate(Employee $employee, ?SettlementContext $context = null): SettlementResult
    {
        $context ??= new SettlementContext;
        $end = $context->terminationDate ?? $employee->termination_date;
        $state = null;
        $rc = BigDecimal::zero();
        $periods = [];
        $error = null;

        try {
            if ($end === null || $employee->hire_date === null) {
                throw PayrollException::invalid("Falta la fecha de ingreso o de cese de {$employee->full_name}.");
            }
            if ($end < $employee->hire_date) {
                throw PayrollException::invalid("La fecha de cese de {$employee->full_name} es anterior a su ingreso.");
            }

            $state = new CalculationState($employee, new PayrollContext($end), $this->parameters, $this->scale);
            $rc = $this->computableRemuneration($state, $context);
            $baseDays = $state->globalParam('dias_base_mes')->required()->toInt();

            $periods['gratificacion'] = $this->gratification($state, $rc, $end);
            $periods['cts'] = $this->cts($state, $context, $rc, $end, $baseDays);
            $periods['vacaciones'] = $this->vacations($state, $context, $rc, $end, $baseDays);
            $periods['indemnizacion_vacacional'] = $this->vacationIndemnity($state, $context, $rc, $baseDays);
            $periods['indemnizacion_despido'] = $this->dismissalIndemnity($state, $context, $rc, $end, $baseDays);

            (new PensionStep)->apply($state);
            if ($employee->health_scheme !== 'sis') {
                // El SIS es un monto mensual fijo: no hay aporte sobre las vacaciones de la liquidación.
                (new HealthContributionStep)->apply($state);
            }
            $this->fifthCategoryAtTermination($state, $context, $end);
        } catch (PayrollException $e) {
            $error = $e->getMessage();
        }

        return $this->result($employee, $end, $state, $rc, array_filter($periods), $error);
    }

    private function computableRemuneration(CalculationState $state, SettlementContext $context): BigDecimal
    {
        return $state->money(BigDecimal::of($state->employee->base_salary)
            ->plus(FamilyAllowanceStep::amount($state) ?? BigDecimal::zero())
            ->plus($context->variableComputable ?? BigDecimal::zero()));
    }

    /** @return array<string, mixed>|null */
    private function gratification(CalculationState $state, BigDecimal $rc, DateTimeImmutable $end): ?array
    {
        if (! $state->flag('gratificacion_aplica')) {
            return null;
        }

        // Semestres calendario: enero a junio y julio a diciembre.
        $month = (int) $end->format('n');
        $semesterStart = $end->setDate((int) $end->format('Y'), $month <= self::MONTHS_PER_SEMESTER ? 1 : self::MONTHS_PER_SEMESTER + 1, 1);
        $from = max($semesterStart, $state->employee->hire_date);
        $months = ServiceTime::fullCalendarMonths($from, $end);
        if ($months === 0) {
            return ['from' => $from->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'months' => 0, 'days' => 0];
        }

        $amount = $rc->multipliedBy($state->param('gratificacion_factor')->required())
            ->multipliedBy($months)->dividedBy(self::MONTHS_PER_SEMESTER, 10, RoundingMode::HALF_UP);
        $state->addEarning(new Line('gratificacion_trunca', 'Gratificación trunca', $state->money($amount), remunerative: false, computable: false, fifthCategory: true));

        $bonusCode = $state->employee->health_scheme === 'eps'
            ? 'gratificacion_bonificacion_extraordinaria_eps_pct'
            : 'gratificacion_bonificacion_extraordinaria_pct';
        $state->addEarning(new Line('bonif_extraordinaria_trunca', 'Bonificación extraordinaria proporcional', $state->money($amount->multipliedBy($state->param($bonusCode)->asRate())), remunerative: false, computable: false, fifthCategory: true));

        return ['from' => $from->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'months' => $months, 'days' => 0];
    }

    /** @return array<string, mixed>|null */
    private function cts(CalculationState $state, SettlementContext $context, BigDecimal $rc, DateTimeImmutable $end, int $baseDays): ?array
    {
        if (! $state->flag('cts_aplica')) {
            return null;
        }

        $from = max($context->ctsPeriodStart ?? $this->lastCtsDepositStart($state, $end), $state->employee->hire_date);
        $time = ServiceTime::monthsAndDays($from, $end);
        $base = $rc->plus(($context->gratificationInCtsPeriod ?? BigDecimal::zero())->multipliedBy($state->param('cts_fraccion_gratificacion')->required()));
        $proportion = $state->param('cts_dias_por_anio')->required()
            ->dividedBy($state->globalParam('cts_dias_por_anio')->required(), 10, RoundingMode::HALF_UP);

        $amount = $base->multipliedBy($this->yearFraction($time, $baseDays))->multipliedBy($proportion);
        $state->addEarning(new Line('cts_trunca', 'CTS trunca', $state->money($amount), remunerative: false, computable: false, fifthCategory: false));

        return ['from' => $from->format('Y-m-d'), 'to' => $end->format('Y-m-d')] + $time;
    }

    /** @return array<string, mixed> */
    private function vacations(CalculationState $state, SettlementContext $context, BigDecimal $rc, DateTimeImmutable $end, int $baseDays): array
    {
        $hire = $state->employee->hire_date;
        $from = max($context->vacationPeriodStart ?? ServiceTime::lastAnniversary($hire, $end), $hire);
        $time = ServiceTime::monthsAndDays($from, $end);
        $daily = $rc->dividedBy($baseDays, 10, RoundingMode::HALF_UP);
        $daysPerYear = $state->param('vacaciones_dias')->required();

        $truncated = $daily->multipliedBy($daysPerYear)->multipliedBy($this->yearFraction($time, $baseDays));
        if ($truncated->isPositive()) {
            $state->addEarning(new Line('vacaciones_truncas', 'Vacaciones truncas', $state->money($truncated), computable: false));
        }
        if ($context->pendingVacationDays > 0) {
            $state->addEarning(new Line('vacaciones_pendientes', "Vacaciones ganadas y no gozadas ({$context->pendingVacationDays} días)", $state->money($daily->multipliedBy($context->pendingVacationDays)), computable: false));
        }

        return ['from' => $from->format('Y-m-d'), 'to' => $end->format('Y-m-d')] + $time + ['pending_days' => $context->pendingVacationDays];
    }

    /** @return array<string, mixed>|null */
    private function vacationIndemnity(CalculationState $state, SettlementContext $context, BigDecimal $rc, int $baseDays): ?array
    {
        if ($context->expiredVacationDays <= 0 || ! $state->flag('indemnizacion_vacacional_aplica')) {
            return null;
        }
        $amount = $rc->dividedBy($baseDays, 10, RoundingMode::HALF_UP)->multipliedBy($context->expiredVacationDays);
        $state->addEarning(new Line('indemnizacion_vacacional', "Indemnización por vacaciones no gozadas ({$context->expiredVacationDays} días)", $state->money($amount), remunerative: false, computable: false, fifthCategory: false));

        return ['days' => $context->expiredVacationDays];
    }

    /** @return array<string, mixed>|null */
    private function dismissalIndemnity(CalculationState $state, SettlementContext $context, BigDecimal $rc, DateTimeImmutable $end, int $baseDays): ?array
    {
        $d = $context->dismissal;
        if ($d === null) {
            return null;
        }
        if ($d->inProbation) {
            $state->warnings[] = 'El despido ocurre dentro del periodo de prueba: no corresponde indemnización.';

            return ['days' => '0', 'reason' => 'probation'];
        }

        $daily = $rc->dividedBy($baseDays, 10, RoundingMode::HALF_UP);
        if ($d->contractType === Dismissal::FIXED_TERM) {
            if ($d->contractEnd === null || $d->contractEnd <= $end) {
                throw PayrollException::invalid('Falta el fin pactado del contrato a plazo fijo, o ya había vencido al cese.');
            }
            $time = ServiceTime::monthsAndDays($end->modify('+1 day'), $d->contractEnd);
            $months = BigDecimal::of($time['months'])->plus(BigDecimal::of($time['days'])->dividedBy($baseDays, 10, RoundingMode::HALF_UP));
            $days = $state->param('indemnizacion_plazo_fijo_dias_por_mes')->required()->multipliedBy($months);
            $basis = ['contract' => Dismissal::FIXED_TERM, 'remaining' => $time];
        } else {
            $time = ServiceTime::monthsAndDays($state->employee->hire_date, $end);
            $years = BigDecimal::of($time['months'])->dividedBy(self::MONTHS_PER_YEAR, 10, RoundingMode::HALF_UP)
                ->plus(BigDecimal::of($time['days'])->dividedBy(self::MONTHS_PER_YEAR * $baseDays, 10, RoundingMode::HALF_UP));
            $days = $state->param('indemnizacion_dias_por_anio')->required()->multipliedBy($years);
            $basis = ['contract' => Dismissal::INDEFINITE, 'service' => $time];
        }

        $cap = $state->param('indemnizacion_tope_dias')->required();
        $capped = $days->isGreaterThan($cap);
        $days = $capped ? $cap : $days;
        $state->addEarning(new Line('indemnizacion_despido', 'Indemnización por despido arbitrario', $state->money($daily->multipliedBy($days)), remunerative: false, computable: false, fifthCategory: false));

        return $basis + ['days' => (string) $days->toScale(4, RoundingMode::HALF_UP), 'capped' => $capped];
    }

    /**
     * Retención de quinta del cese (Reglamento LIR art. 40): sobre lo cobrado en el año más la planilla del mes
     * y la liquidación, sin proyectar meses ni gratificaciones futuras, menos todo lo retenido.
     */
    private function fifthCategoryAtTermination(CalculationState $state, SettlementContext $context, DateTimeImmutable $end): void
    {
        $history = $context->fifthCategoryHistory;
        if ($history === null) {
            $state->warnings[] = 'La quinta del cese no se calculó: falta el historial del año (FifthCategoryHistory).';

            return;
        }
        $month = (int) $end->format('n');
        $withholdings = $history->withholdingsByMonth;
        if ($context->lastMonthWithholding !== null) {
            $withholdings[$month] = ($withholdings[$month] ?? BigDecimal::zero())->plus($context->lastMonthWithholding);
        }
        $income = $state->sum(array_filter($state->earnings, fn (Line $l) => $l->isFifthCategoryIncome()))
            ->plus($context->lastMonthFifthIncome ?? BigDecimal::zero());

        $result = (new FifthCategoryCalculator(fn (string $code) => $state->globalParam($code)))->calculate(
            month: $month,
            currentMonthIncome: $income,
            projectedMonthlyIncome: BigDecimal::zero(),
            extraordinaryIncome: BigDecimal::zero(),
            history: new \App\Payroll\Tax\FifthCategoryHistory($history->previousIncome, $withholdings),
            remainingMonths: 0,
            final: true,
        );
        $state->fifthCategory = $result->toArray();
        if ($result->withholding->isPositive()) {
            $state->addDeduction(new Line('renta_quinta_cese', 'Retención de quinta categoría del cese', $state->money($result->withholding)));
        }
        if ($result->refund->isPositive()) {
            self::refund($state, $result->refund);
        }
    }

    /** Exceso retenido de quinta: se devuelve solo si el parámetro está confirmado; si no, queda como aviso. */
    public static function refund(CalculationState $state, BigDecimal $refund): void
    {
        $rule = $state->globalParam('quinta_devuelve_exceso');
        if ($rule->value === null || $rule->value->isZero()) {
            $state->warnings[] = "Hay un exceso de retención de quinta de S/ {$state->money($refund)}: la devolución espera que se confirme «quinta_devuelve_exceso».";

            return;
        }
        $state->addEarning(new Line('devolucion_quinta', 'Devolución de exceso de retención de quinta', $state->money($refund), remunerative: false, computable: false, fifthCategory: false));
    }

    /** Inicio del periodo de CTS sin depositar: el 1 del último mes de depósito (mayo o noviembre) hasta el cese. */
    private function lastCtsDepositStart(CalculationState $state, DateTimeImmutable $end): DateTimeImmutable
    {
        $months = array_map('intval', $state->globalParam('cts_meses_deposito')->json());
        sort($months);
        $year = (int) $end->format('Y');
        $month = (int) $end->format('n');
        $candidates = array_filter($months, fn (int $m) => $m <= $month);

        return $candidates === []
            ? $end->setDate($year - 1, max($months), 1)
            : $end->setDate($year, max($candidates), 1);
    }

    /** @param array{months: int, days: int} $time */
    private function yearFraction(array $time, int $baseDays): BigDecimal
    {
        return BigDecimal::of($time['months'])->dividedBy(self::MONTHS_PER_YEAR, 10, RoundingMode::HALF_UP)
            ->plus(BigDecimal::of($time['days'])->dividedBy(self::MONTHS_PER_YEAR * $baseDays, 10, RoundingMode::HALF_UP));
    }

    /** @param array<string, array<string, mixed>> $periods */
    private function result(Employee $employee, ?DateTimeImmutable $end, ?CalculationState $s, BigDecimal $rc, array $periods, ?string $error): SettlementResult
    {
        $zero = BigDecimal::zero()->toScale($this->scale);
        $gross = $s?->sum($s->earnings) ?? $zero;
        $deductions = $s?->sum($s->deductions) ?? $zero;

        return new SettlementResult(
            employeeId: (string) $employee->getKey(),
            terminationDate: $end?->format('Y-m-d'),
            status: $error === null ? SettlementResult::STATUS_OK : SettlementResult::STATUS_ERROR,
            error: $error,
            computableRemuneration: $rc,
            grossTotal: $gross,
            totalDeductions: $deductions,
            netTotal: $gross->minus($deductions),
            earnings: array_values($s?->earnings ?? []),
            deductions: array_values($s?->deductions ?? []),
            employerContributions: array_values($s?->employerContributions ?? []),
            periods: $periods,
            parametersUsed: array_map(fn (ParameterValue $p) => $p->toArray(), $s?->usedParameters() ?? []),
            warnings: array_values(array_unique($s?->warnings ?? [])),
            fifthCategory: $s?->fifthCategory,
        );
    }
}
