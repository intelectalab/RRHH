<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Shared\Payroll\OpeningBalances;
use App\Shared\Permissions\DataScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PER-11 · Libro de retenciones de quinta (fifth_category_ledger, de solo inserción: un recálculo es otra fila
 * con secuencia mayor). Consulta con el alcance de quien la pide, resumen del ejercicio por trabajador (lo
 * retenido de cada mes es su última secuencia) y conciliación con las boletas de los periodos cerrados.
 */
final class FifthCategoryLedger
{
    /** Periodos cuyo cálculo ya es definitivo (lo que se pagó). */
    private const CLOSED = ['closed', 'paid', 'accounted'];

    public function __construct(private readonly DataScope $scope, private readonly OpeningBalances $opening) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters, Request $request): Builder
    {
        $q = DB::table('fifth_category_ledger AS g')
            ->join('employments AS e', 'e.id', '=', 'g.employment_id')
            ->join('people AS p', 'p.id', '=', 'e.person_id')
            ->join('legal_entities AS le', 'le.id', '=', 'e.legal_entity_id')
            ->leftJoin('payroll_runs AS r', 'r.id', '=', 'g.payroll_run_id')
            ->orderBy('g.fiscal_year')->orderBy('g.period_month')->orderBy('p.paternal_surname')->orderBy('g.sequence')->orderBy('g.id')
            ->select(['g.*', 'e.employee_code', 'le.legal_name AS legal_entity_name', 'r.status AS run_status',
                DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) AS person_name")]);
        $this->scope->applyEmployment($q, $request, 'payroll.fifth_category_ledger.view', 'g.employment_id');
        foreach (['fiscal_year', 'period_month'] as $f) {
            if (isset($filters[$f]) && is_numeric($filters[$f])) {
                $q->where('g.'.$f, (int) $filters[$f]);
            }
        }
        if (isset($filters['employment_id']) && is_string($filters['employment_id']) && $filters['employment_id'] !== '') {
            $q->where('g.employment_id', $filters['employment_id']);
        }
        if (isset($filters['legal_entity_id']) && is_string($filters['legal_entity_id']) && $filters['legal_entity_id'] !== '') {
            $q->where('e.legal_entity_id', $filters['legal_entity_id']);
        }
        if (isset($filters['entry_kind']) && is_string($filters['entry_kind']) && $filters['entry_kind'] !== '') {
            $q->where('g.entry_kind', $filters['entry_kind']);
        }

        return $q;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(\stdClass $row): array
    {
        $money = fn (mixed $v): array => ['amount' => (string) BigDecimal::of((string) ($v ?? '0'))->toScale(2), 'currency' => (string) $row->currency];

        return [
            'id' => (string) $row->id, 'employment_id' => (string) $row->employment_id, 'employee_code' => (string) ($row->employee_code ?? ''),
            'person_name' => trim((string) ($row->person_name ?? '')), 'legal_entity_name' => (string) ($row->legal_entity_name ?? ''),
            'fiscal_year' => (int) $row->fiscal_year, 'period_month' => (int) $row->period_month, 'sequence' => (int) $row->sequence,
            'entry_kind' => (string) $row->entry_kind, 'payroll_run_id' => $row->payroll_run_id, 'run_status' => $row->run_status ?? null,
            'gross_annual_income' => $money($row->gross_annual_income), 'deduction_amount' => $money($row->deduction_amount),
            'net_annual_income' => $money($row->net_annual_income), 'annual_tax' => $money($row->annual_tax),
            'prior_withholdings_deducted' => $money($row->prior_withholdings_deducted), 'divisor' => $row->divisor === null ? null : (int) $row->divisor,
            'ordinary_withholding' => $money($row->ordinary_withholding), 'additional_withholding' => $money($row->additional_withholding),
            'withholding_amount' => $money($row->withholding_amount), 'uit_value' => $money($row->uit_value),
            'parameters' => $this->object($row->parameters), 'trace' => $this->object($row->trace),
            'engine_version' => (string) $row->engine_version, 'created_at' => CarbonImmutable::parse((string) $row->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** JSON guardado como objeto del contrato: una lista de parámetros viaja indexada por su código. */
    private function object(mixed $json): object
    {
        $d = json_decode((string) ($json ?? '{}'), true);
        if (! is_array($d)) {
            return (object) [];
        }
        if (array_is_list($d) && $d !== [] && is_array($d[0]) && isset($d[0]['code'])) {
            $d = array_column($d, null, 'code');
        }

        return (object) $d;
    }

    /**
     * Resumen del ejercicio de un trabajador: por mes la retención vigente (última secuencia, sin corridas
     * descartadas), el acumulado, lo traído del sistema anterior y la conciliación con las boletas cerradas.
     *
     * @return array<string, mixed>
     */
    public function summary(string $employmentId, int $year): array
    {
        $rows = DB::table('fifth_category_ledger AS g')->leftJoin('payroll_runs AS r', 'r.id', '=', 'g.payroll_run_id')
            ->where('g.employment_id', $employmentId)->where('g.fiscal_year', $year)
            ->where(fn ($q) => $q->whereNull('g.payroll_run_id')->orWhere('r.status', '<>', 'discarded'))
            ->orderBy('g.period_month')->orderBy('g.sequence')->orderBy('g.id')
            ->get(['g.period_month', 'g.entry_kind', 'g.sequence', 'g.withholding_amount', 'g.currency']);
        $months = [];
        $currency = null;
        foreach ($rows as $r) {
            $currency = (string) $r->currency;
            // La retención mensual y su corrección son el mismo cálculo del mes: cuenta la última de las dos.
            $group = in_array($r->entry_kind, ['monthly', 'correction'], true) ? 'monthly' : (string) $r->entry_kind;
            $months[(int) $r->period_month][$group] = ['amount' => (string) $r->withholding_amount, 'sequence' => (int) $r->sequence];
        }
        $accumulated = BigDecimal::zero();
        $out = [];
        ksort($months);
        foreach ($months as $m => $groups) {
            $month = BigDecimal::zero();
            foreach ($groups as $g) {
                $month = $month->plus($g['amount']);
            }
            $accumulated = $accumulated->plus($month);
            $out[] = ['period_month' => $m, 'withheld' => (string) $month->toScale(2), 'sequence' => $groups['monthly']['sequence'] ?? null,
                'accumulated' => (string) $accumulated->toScale(2)];
        }
        $opening = $this->opening->fifthCategory($employmentId, $year);
        $closed = $this->closedPayslipsWithholding($employmentId, $year);
        $ledgerClosed = $this->closedLedgerWithholding($employmentId, $year);

        return [
            'employment_id' => $employmentId, 'fiscal_year' => $year, 'currency' => $currency, 'months' => $out,
            'accumulated' => (string) $accumulated->toScale(2),
            'opening' => ['cutoff' => $opening['cutoff'], 'income' => (string) BigDecimal::of($opening['income'])->toScale(2), 'withheld' => (string) BigDecimal::of($opening['withheld'])->toScale(2)],
            'closed_payslips' => (string) $closed->toScale(2),
            'closed_ledger' => (string) $ledgerClosed->toScale(2),
            'reconciled' => $closed->isEqualTo($ledgerClosed),
        ];
    }

    /** Suma de la línea de retención en las corridas de periodos cerrados del ejercicio (lo que salió en boletas). */
    private function closedPayslipsWithholding(string $employmentId, int $year): BigDecimal
    {
        $codes = $this->incomeTaxConcepts($employmentId);
        if ($codes === []) {
            return BigDecimal::zero();
        }
        $sum = DB::table('payroll_run_lines AS l')
            ->join('payroll_run_employments AS re', 're.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_runs AS r', 'r.id', '=', 'l.payroll_run_id')
            ->join('payroll_periods AS p', 'p.id', '=', 'r.payroll_period_id')
            ->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')
            ->where('re.employment_id', $employmentId)->whereIn('c.code', $codes)->whereIn('p.status', self::CLOSED)
            ->whereRaw('EXTRACT(YEAR FROM COALESCE(p.payment_date, p.ends_on)) = ?', [$year])
            ->whereRaw("r.run_number = (SELECT max(r2.run_number) FROM payroll_runs r2 WHERE r2.payroll_period_id = p.id AND r2.kind <> 'simulation' AND r2.status IN ('completed','approved','closed'))")
            ->sum('l.amount');

        return BigDecimal::of((string) ($sum ?: '0'));
    }

    /** Lo que el libro dice de esas mismas corridas (última secuencia de cada una). */
    private function closedLedgerWithholding(string $employmentId, int $year): BigDecimal
    {
        $sum = DB::table('fifth_category_ledger AS g')
            ->join('payroll_runs AS r', 'r.id', '=', 'g.payroll_run_id')
            ->join('payroll_periods AS p', 'p.id', '=', 'r.payroll_period_id')
            ->where('g.employment_id', $employmentId)->where('g.fiscal_year', $year)->whereIn('p.status', self::CLOSED)
            ->where('g.withholding_amount', '>', 0)
            ->whereRaw("r.run_number = (SELECT max(r2.run_number) FROM payroll_runs r2 WHERE r2.payroll_period_id = p.id AND r2.kind <> 'simulation' AND r2.status IN ('completed','approved','closed'))")
            ->whereRaw('g.sequence = (SELECT max(g2.sequence) FROM fifth_category_ledger g2 WHERE g2.payroll_run_id = g.payroll_run_id AND g2.employment_id = g.employment_id AND g2.period_month = g.period_month AND g2.entry_kind = g.entry_kind)')
            ->sum('g.withholding_amount');

        return BigDecimal::of((string) ($sum ?: '0'));
    }

    /**
     * @return list<string>
     */
    private function incomeTaxConcepts(string $employmentId): array
    {
        $country = (string) DB::table('employments AS e')->join('legal_entities AS le', 'le.id', '=', 'e.legal_entity_id')
            ->where('e.id', $employmentId)->value('le.country_code');
        if (! DataPayrollCountryPack::exists($country)) {
            return [];
        }
        $tax = (new DataPayrollCountryPack($country))->incomeTax();

        return $tax === null ? [] : [(string) $tax['concepto']];
    }
}
