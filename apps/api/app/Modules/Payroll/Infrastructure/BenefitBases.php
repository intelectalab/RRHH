<?php

namespace App\Modules\Payroll\Infrastructure;

use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Time\VacationBalances;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BSO-1 a BSO-3 · Datos de los procesos de beneficios sociales que las fórmulas del paquete de país necesitan y que
 * no salen del mes: meses (y días) computables del semestre, la última gratificación para la CTS y la participación
 * en utilidades asignada en la distribución aprobada. Solo calendario y lectura de lo ya calculado: los factores,
 * fracciones y porcentajes legales viven en los parámetros con vigencia.
 *
 * Semestres (Ley 27735 y DS 001-97-TR, calendario): la gratificación de julio (o un proceso de enero a julio) cubre enero a junio y la de diciembre,
 * julio a diciembre; la CTS cubre los seis meses que terminan el mes anterior al del depósito.
 */
final class BenefitBases
{
    /** @var list<string> */
    public const VARIABLES = ['meses_computables', 'dias_computables', 'gratificacion_ultima', 'utilidades_asignadas', 'remuneracion_del_mes',
        'liq_meses_gratificacion', 'liq_meses_cts', 'liq_dias_cts', 'liq_gratificacion_cts', 'liq_meses_vacaciones', 'liq_dias_vacaciones',
        'liq_dias_vacaciones_pendientes', 'liq_dias_vacaciones_vencidas', 'liq_indemnizacion', 'liq_plazo_fijo', 'liq_meses_restantes',
        'liq_dias_restantes', 'liq_meses_servicio', 'liq_dias_servicio'];

    /**
     * LIQ-1 · Datos de la liquidación que llegan como pedido (no como dato hipotético): la indemnización se pide y aquí se
     * descarta en periodo de prueba; la remuneración pendiente del mes solo se paga si se pide (si no, la paga la planilla).
     *
     * @var list<string>
     */
    public const SETTLEMENT_INPUTS = ['liq_indemnizacion', 'remuneracion_del_mes'];

    private const SEMESTER_MONTHS = 6;

    /** Estados de corrida cuya gratificación ya cuenta para la CTS. */
    private const COUNTED_RUNS = ['completed', 'approved', 'closed'];

    public function __construct(
        private readonly LegalParameters $legal,
        private readonly VacationBalances $vacations,
    ) {}

    /**
     * @param  list<array{code: string, message: string}>  $errors
     * @param  array<string, mixed>  $sources
     * @param  array<string, string|bool>  $inputs  pedidos de la liquidación (SETTLEMENT_INPUTS)
     * @return array<string, BigDecimal>
     */
    public function variables(string $employmentId, string $legalEntityId, string $periodKind, string $to, string $hire, ?string $termination, array &$errors, array &$sources, array $inputs = []): array
    {
        $vars = array_fill_keys(self::VARIABLES, BigDecimal::zero());
        $vars['remuneracion_del_mes'] = BigDecimal::one();
        if ($periodKind === 'settlement') {
            return $this->settlement($vars, $employmentId, $legalEntityId, $to, $hire, $inputs, $sources);
        }
        if ($periodKind === 'gratification') {
            [$semFrom, $semTo] = self::gratificationSemester($to);
            $from = max($semFrom, $hire);
            $until = $termination === null ? $semTo : min($semTo, $termination);
            $months = $from > $until ? 0 : self::fullCalendarMonths($from, $until);
            $vars['meses_computables'] = BigDecimal::of($months);
            $sources['benefit'] = ['kind' => 'gratification', 'semester' => ['from' => $semFrom, 'to' => $semTo],
                'computable' => ['from' => $from, 'to' => $until, 'months' => $months]];
        } elseif ($periodKind === 'cts') {
            [$semFrom, $semTo] = self::ctsSemester($to);
            $from = max($semFrom, $hire);
            $until = $termination === null ? $semTo : min($semTo, $termination);
            $time = $from > $until ? ['months' => 0, 'days' => 0] : self::monthsAndDays($from, $until);
            $grat = $this->lastGratification($employmentId, $semFrom, $semTo);
            $vars['meses_computables'] = BigDecimal::of($time['months']);
            $vars['dias_computables'] = BigDecimal::of($time['days']);
            $vars['gratificacion_ultima'] = BigDecimal::of($grat['amount']);
            $account = DB::table('person_bank_accounts')->where('employment_id', $employmentId)->where('purpose', 'cts')
                ->where('status', 'active')->whereNull('deleted_at');
            EffectiveDated::applyAsOf($account, $to);
            $deposit = $account->first(['id', 'bank_code', 'currency']);
            // RN-BSO-05 · Sin entidad depositaria registrada no se genera su línea: queda como error de la relación.
            if ($deposit === null) {
                $errors[] = ['code' => 'cts_account_missing', 'message' => (string) trans('payroll.context.cts_account_missing')];
            }
            $sources['benefit'] = ['kind' => 'cts', 'semester' => ['from' => $semFrom, 'to' => $semTo],
                'computable' => ['from' => $from, 'to' => $until] + $time, 'last_gratification' => $grat,
                'deposit_account' => $deposit === null ? null : ['id' => (string) $deposit->id, 'bank_code' => (string) $deposit->bank_code]];
        } elseif ($periodKind === 'profit_sharing') {
            $item = DB::table('profit_sharing_items as i')
                ->join('profit_sharing_distributions as d', 'd.id', '=', 'i.profit_sharing_distribution_id')
                ->where('i.employment_id', $employmentId)->where('d.legal_entity_id', $legalEntityId)
                ->whereIn('d.status', ['approved', 'paid'])->where('d.fiscal_year', (int) substr($to, 0, 4) - 1)
                ->first(['i.amount', 'i.amount_by_days', 'i.amount_by_remuneration', 'd.id as distribution_id', 'd.fiscal_year']);
            $vars['utilidades_asignadas'] = BigDecimal::of((string) ($item->amount ?? '0'));
            $sources['benefit'] = ['kind' => 'profit_sharing', 'distribution' => $item === null ? null : [
                'id' => (string) $item->distribution_id, 'fiscal_year' => (int) $item->fiscal_year,
                'by_days' => (string) $item->amount_by_days, 'by_remuneration' => (string) $item->amount_by_remuneration]];
        }

        return $vars;
    }

    /**
     * LIQ-1 · Tiempos de la liquidación al cese ($to): meses del semestre de gratificación, tiempo desde el inicio del
     * semestre de CTS sin depositar (último mes de depósito del parámetro cts_meses_deposito), año de servicios en curso
     * para las vacaciones truncas, días ganados sin gozar y vencidos del libro de vacaciones, tiempo de servicios y lo
     * que falta del contrato a plazo fijo. La indemnización se pide y no procede en periodo de prueba (RN-LIQ-03).
     *
     * @param  array<string, BigDecimal>  $vars
     * @param  array<string, string|bool>  $inputs
     * @param  array<string, mixed>  $sources
     * @return array<string, BigDecimal>
     */
    private function settlement(array $vars, string $employmentId, string $legalEntityId, string $to, string $hire, array $inputs, array &$sources): array
    {
        $end = CarbonImmutable::parse($to);
        $warnings = [];
        $vars['remuneracion_del_mes'] = self::flag($inputs['remuneracion_del_mes'] ?? false) ? BigDecimal::one() : BigDecimal::zero();

        $gratStart = $end->setDate($end->year, $end->month <= self::SEMESTER_MONTHS ? 1 : self::SEMESTER_MONTHS + 1, 1)->toDateString();
        $gratFrom = max($gratStart, $hire);
        $gratMonths = $gratFrom > $to ? 0 : self::fullCalendarMonths($gratFrom, $to);
        $vars['liq_meses_gratificacion'] = BigDecimal::of($gratMonths);

        $country = (string) DB::table('legal_entities')->where('id', $legalEntityId)->value('country_code');
        $raw = (new PayrollParameters($this->legal, $country, null, $legalEntityId, $to))->resolve('cts_meses_deposito')['value'] ?? null;
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $depositMonths = is_array($raw) ? array_values(array_map('intval', $raw)) : [];
        $cts = null;
        if ($depositMonths === []) {
            $warnings[] = ['code' => 'cts_deposit_months_missing', 'message' => (string) trans('payroll.settlements.warnings.cts_deposit_months_missing')];
        } else {
            sort($depositMonths);
            $before = array_values(array_filter($depositMonths, fn (int $m): bool => $m <= $end->month));
            $ctsStart = $before === [] ? $end->setDate($end->year - 1, max($depositMonths), 1) : $end->setDate($end->year, max($before), 1);
            $ctsFrom = max($ctsStart->toDateString(), $hire);
            $time = $ctsFrom > $to ? ['months' => 0, 'days' => 0] : self::monthsAndDays($ctsFrom, $to);
            $grat = $this->lastGratification($employmentId, $ctsStart->toDateString(), $to);
            $vars['liq_meses_cts'] = BigDecimal::of($time['months']);
            $vars['liq_dias_cts'] = BigDecimal::of($time['days']);
            $vars['liq_gratificacion_cts'] = BigDecimal::of($grat['amount']);
            $cts = ['from' => $ctsFrom, 'to' => $to] + $time + ['gratification' => $grat];
        }

        $hireDate = CarbonImmutable::parse($hire);
        $anniversary = $hireDate->addYearsNoOverflow((int) $hireDate->diffInYears($end))->toDateString();
        $vacTime = $anniversary > $to ? ['months' => 0, 'days' => 0] : self::monthsAndDays($anniversary, $to);
        $vars['liq_meses_vacaciones'] = BigDecimal::of($vacTime['months']);
        $vars['liq_dias_vacaciones'] = BigDecimal::of($vacTime['days']);
        $balance = $this->vacations->balance($employmentId, $to);
        $pending = BigDecimal::zero();
        foreach ($balance['periods'] as $period) {
            if (in_array($period['status'] ?? '', ['earned', 'overdue'], true)) {
                $pending = $pending->plus(BigDecimal::of((string) ($period['available'] ?? '0')));
            }
        }
        $pending = BigDecimal::max($pending, BigDecimal::zero());
        $overdue = BigDecimal::min(BigDecimal::max(BigDecimal::of($balance['overdue_days']), BigDecimal::zero()), $pending);
        $vars['liq_dias_vacaciones_pendientes'] = $pending;
        $vars['liq_dias_vacaciones_vencidas'] = $overdue;

        $service = self::monthsAndDays($hire, $to);
        $vars['liq_meses_servicio'] = BigDecimal::of($service['months']);
        $vars['liq_dias_servicio'] = BigDecimal::of($service['days']);
        $contract = DB::table('contracts as c')->join('contract_types as t', 't.id', '=', 'c.contract_type_id')
            ->where('c.employment_id', $employmentId)->whereNull('c.deleted_at')->whereIn('c.status', ['active', 'signed', 'expired', 'terminated'])
            ->where('c.valid_from', '<=', $to)->orderByDesc('c.valid_from')
            ->first(['c.id', 'c.valid_to', 'c.trial_period_ends_on', 't.category']);
        $probation = DB::table('employments')->where('id', $employmentId)->value('probation_ends_on') ?? $contract?->trial_period_ends_on;
        $inProbation = $probation !== null && substr((string) $probation, 0, 10) >= $to;
        $fixedTerm = $contract !== null && $contract->category === 'fixed_term' && $contract->valid_to !== null && substr((string) $contract->valid_to, 0, 10) > $to;
        $remaining = ['months' => 0, 'days' => 0];
        if ($fixedTerm) {
            $remaining = self::monthsAndDays($end->addDay()->toDateString(), substr((string) $contract->valid_to, 0, 10));
            $vars['liq_plazo_fijo'] = BigDecimal::one();
            $vars['liq_meses_restantes'] = BigDecimal::of($remaining['months']);
            $vars['liq_dias_restantes'] = BigDecimal::of($remaining['days']);
        }
        $requested = self::flag($inputs['liq_indemnizacion'] ?? false);
        if ($requested && $inProbation) {
            $warnings[] = ['code' => 'severance_in_probation', 'message' => (string) trans('payroll.settlements.warnings.severance_in_probation')];
        }
        $vars['liq_indemnizacion'] = $requested && ! $inProbation ? BigDecimal::one() : BigDecimal::zero();

        $sources['benefit'] = ['kind' => 'settlement', 'termination_date' => $to,
            'gratification' => ['from' => $gratFrom, 'to' => $to, 'months' => $gratMonths],
            'cts' => $cts,
            'vacations' => ['from' => $anniversary, 'to' => $to] + $vacTime + ['pending_days' => (string) $pending, 'overdue_days' => (string) $overdue],
            'service' => ['from' => $hire, 'to' => $to] + $service,
            'severance' => ['requested' => $requested, 'in_probation' => $inProbation, 'fixed_term' => $fixedTerm,
                'contract_id' => $contract === null ? null : (string) $contract->id, 'remaining' => $remaining],
            'pending_salary' => self::flag($inputs['remuneracion_del_mes'] ?? false),
            'warnings' => $warnings];

        return $vars;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /** @return array{0: string, 1: string} */
    public static function gratificationSemester(string $to): array
    {
        $d = CarbonImmutable::parse($to);
        $start = $d->month <= self::SEMESTER_MONTHS + 1 ? $d->setDate($d->year, 1, 1) : $d->setDate($d->year, self::SEMESTER_MONTHS + 1, 1);

        return [$start->toDateString(), $start->addMonths(self::SEMESTER_MONTHS)->subDay()->toDateString()];
    }

    /** @return array{0: string, 1: string} Los seis meses que terminan el mes anterior al del depósito. */
    public static function ctsSemester(string $to): array
    {
        $first = CarbonImmutable::parse($to)->startOfMonth();

        return [$first->subMonths(self::SEMESTER_MONTHS)->toDateString(), $first->subDay()->toDateString()];
    }

    public static function fullCalendarMonths(string $from, string $to): int
    {
        $count = 0;
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        for ($m = $start->startOfMonth(); $m <= $end; $m = $m->addMonthNoOverflow()->startOfMonth()) {
            if ($m >= $start && $m->endOfMonth()->startOfDay() <= $end) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array{months: int, days: int} meses completos desde la fecha de inicio y días restantes */
    public static function monthsAndDays(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to)->addDay();
        $months = 0;
        while ($start->addMonthsNoOverflow($months + 1) <= $end) {
            $months++;
        }

        return ['months' => $months, 'days' => (int) $start->addMonthsNoOverflow($months)->diffInDays($end)];
    }

    /**
     * Gratificación pagada dentro del semestre de CTS (la de julio para mayo-octubre, la de diciembre para
     * noviembre-abril): de la corrida vigente del proceso de gratificación o, si la organización empezó a mitad
     * de año, del acumulado traído (PER-12).
     *
     * @return array{amount: string, source: string|null}
     */
    private function lastGratification(string $employmentId, string $from, string $to): array
    {
        $line = DB::table('payroll_run_lines as l')
            ->join('payroll_run_employments as re', 're.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_runs as r', 'r.id', '=', 're.payroll_run_id')
            ->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')
            ->join('payroll_concepts as c', 'c.id', '=', 'l.payroll_concept_id')
            ->where('re.employment_id', $employmentId)->where('p.kind', 'gratification')->where('c.code', 'gratificacion')
            ->whereIn('r.status', self::COUNTED_RUNS)->whereNotIn('r.kind', ['simulation'])
            ->whereBetween('p.ends_on', [$from, $to])
            ->orderByDesc('p.ends_on')->orderByDesc('r.created_at')->first(['l.amount', 'r.id as run_id']);
        if ($line !== null) {
            return ['amount' => (string) $line->amount, 'source' => 'payroll_run:'.$line->run_id];
        }
        $opening = DB::table('payroll_opening_balances')->where('employment_id', $employmentId)->where('kind', 'gratification_base')
            ->whereNull('deleted_at')->whereBetween('cutoff_date', [$from, $to])->orderByDesc('cutoff_date')->first(['id', 'amount']);

        return $opening === null ? ['amount' => '0', 'source' => null] : ['amount' => (string) $opening->amount, 'source' => 'opening_balance:'.$opening->id];
    }
}
