<?php

namespace App\Modules\CoreHR\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · Costo laboral del mes: suma de los sueldos base mensuales vigentes hoy de las relaciones
 * vigentes, por moneda (montos {amount, currency} con decimal exacto, regla 12). Exige el permiso de
 * campo de sueldos y deja la lectura en el registro de accesos (reglas 6 y 7).
 *
 * Solo suma sueldos con base mensual; los de base diaria u horaria se cuentan aparte (excluded) porque
 * llevarlos a mes depende de la jornada y eso lo calcula planilla, no el tablero.
 */
final class LaborCostCard implements HomeCard
{
    public function key(): string
    {
        return 'labor_cost';
    }

    public function permission(): string
    {
        return 'people.employments.salary.view';
    }

    public function audiences(): array
    {
        return [self::HR];
    }

    public function order(): int
    {
        return 80;
    }

    public function compute(HomeContext $context): array
    {
        $today = $context->today->toDateString();
        $query = HomeSql::employments()->whereIn('e.status', HomeSql::OPEN)
            ->join('employment_compensations as c', function ($j) use ($today): void {
                $j->on('c.employment_id', '=', 'e.id')->where('c.valid_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('c.valid_to')->orWhere('c.valid_to', '>=', $today));
            });
        $context->restrict($query, 'e.id', $this->permission());

        $totals = [];
        $excluded = 0;
        foreach ($query->groupBy('c.currency', 'c.salary_basis')->get(['c.currency', 'c.salary_basis', DB::raw('sum(c.base_salary)::text as total'), DB::raw('count(*) as n')]) as $row) {
            if ($row->salary_basis !== 'monthly') {
                $excluded += (int) $row->n;

                continue;
            }
            $currency = trim((string) $row->currency);
            $totals[$currency] = BigDecimal::of($totals[$currency] ?? '0')->plus((string) $row->total)->toScale(2)->__toString();
        }
        ksort($totals);
        $amounts = array_map(fn (string $c, string $a) => ['amount' => $a, 'currency' => $c], array_keys($totals), array_values($totals));
        $context->logSensitiveRead('salary', ['base_salary'], $this->key());

        return [
            'value' => count($amounts) === 1 ? $amounts[0]['amount'] : null,
            'link' => '/personas/colaboradores?filter[status]=active,suspended,terminating',
            'meta' => ['amounts' => $amounts, 'excluded_non_monthly' => $excluded],
        ];
    }
}
