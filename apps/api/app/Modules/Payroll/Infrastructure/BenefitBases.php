<?php

namespace App\Modules\Payroll\Infrastructure;

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
    public const VARIABLES = ['meses_computables', 'dias_computables', 'gratificacion_ultima', 'utilidades_asignadas'];

    private const SEMESTER_MONTHS = 6;

    /** Estados de corrida cuya gratificación ya cuenta para la CTS. */
    private const COUNTED_RUNS = ['completed', 'approved', 'closed'];

    /**
     * @param  list<array{code: string, message: string}>  $errors
     * @param  array<string, mixed>  $sources
     * @return array<string, BigDecimal>
     */
    public function variables(string $employmentId, string $legalEntityId, string $periodKind, string $to, string $hire, ?string $termination, array &$errors, array &$sources): array
    {
        $vars = array_fill_keys(self::VARIABLES, BigDecimal::zero());
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
