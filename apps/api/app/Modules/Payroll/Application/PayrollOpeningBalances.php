<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Infrastructure\Models\PayrollOpeningBalance;
use App\Shared\Payroll\OpeningBalances;
use Brick\Math\BigDecimal;

/**
 * PER-12 · Acumulados del ejercicio traídos del sistema anterior (saldos de apertura con fecha de corte). Los
 * borrados (reversión de la importación) no cuentan.
 */
final class PayrollOpeningBalances implements OpeningBalances
{
    public function fifthCategory(string $employmentId, int $fiscalYear): array
    {
        $rows = PayrollOpeningBalance::query()->where('employment_id', $employmentId)->where('fiscal_year', $fiscalYear)
            ->whereIn('kind', ['fifth_income', 'fifth_withheld'])->get(['kind', 'amount', 'period_month', 'cutoff_date']);
        $income = BigDecimal::zero();
        $withheld = BigDecimal::zero();
        $byMonth = [];
        $cutoff = null;
        foreach ($rows as $r) {
            $cutoff = max($cutoff ?? '', substr((string) $r->cutoff_date, 0, 10));
            if ($r->kind === 'fifth_income') {
                $income = $income->plus((string) $r->amount);

                continue;
            }
            $withheld = $withheld->plus((string) $r->amount);
            // Sin mes, la retención previa se deduce completa desde el primer cálculo (igual que un certificado sin detalle).
            $month = (int) ($r->period_month ?? 0);
            $byMonth[$month] = (string) BigDecimal::of($byMonth[$month] ?? '0')->plus((string) $r->amount);
        }

        return ['cutoff' => $cutoff, 'income' => (string) $income, 'withheld' => (string) $withheld, 'withheld_by_month' => $byMonth];
    }

    public function total(string $employmentId, int $fiscalYear, string $kind, ?int $semester = null): array
    {
        $q = PayrollOpeningBalance::query()->where('employment_id', $employmentId)->where('fiscal_year', $fiscalYear)->where('kind', $kind)
            ->when($semester !== null, fn ($w) => $w->where('semester', $semester));
        $amount = BigDecimal::zero();
        $cutoff = null;
        foreach ($q->get(['amount', 'cutoff_date']) as $r) {
            $amount = $amount->plus((string) $r->amount);
            $cutoff = max($cutoff ?? '', substr((string) $r->cutoff_date, 0, 10));
        }

        return ['cutoff' => $cutoff, 'amount' => (string) $amount];
    }
}
