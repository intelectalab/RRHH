<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\PayrollException;
use Brick\Math\BigDecimal;

/**
 * Construcción civil (convenio CAPECO-FTCCP y la tabla salarial del año): jornal, BUC y movilidad.
 *
 *   jornal básico   = cc_jornal_basico[categoría]          (operario, oficial, peón)
 *   remuneración    = jornal básico × jornales pagados     (incluye dominicales y feriados)
 *   BUC             = jornal básico × cc_buc_pct[categoría] ÷ 100 × jornales pagados [validar la base]
 *   movilidad       = cc_movilidad_dia × días efectivamente laborados (no remunerativa)
 *
 * Los jornales pagados y los días laborados llegan en el contexto (los arma el tareo de obra).
 */
final class ConstructionWageStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $e = $state->employee;
        $paid = $state->context->constructionPaidDays;
        $worked = $state->context->constructionWorkedDays ?? $paid;
        if ($paid === null) {
            throw PayrollException::invalid("Faltan los jornales pagados de {$e->full_name} en el periodo (constructionPaidDays).");
        }
        $category = (string) ($e->construction_category ?? '');
        $wages = $state->param('cc_jornal_basico')->json();
        $buc = $state->param('cc_buc_pct')->json();
        if (! isset($wages[$category], $buc[$category])) {
            throw PayrollException::invalid("La categoría de construcción civil «{$category}» de {$e->full_name} no está en la tabla del periodo.");
        }
        $daily = BigDecimal::of((string) $wages[$category]);
        $state->dailyWage = $daily;
        $state->workedDays = $paid;
        $basic = $state->money($daily->multipliedBy($paid));
        $state->monthlySalary = $basic;
        $state->addEarning(new Line('rem_basica', "Jornal básico ({$paid} jornales)", $basic));
        $state->addEarning(new Line('cc_buc', 'Bonificación unificada de construcción (BUC)', $state->money($basic->multipliedBy(BigDecimal::of((string) $buc[$category]))->dividedBy(100, 10)), computable: false));
        if ($worked > 0) {
            $state->addEarning(new Line('cc_movilidad', "Movilidad ({$worked} días)", $state->money($state->param('cc_movilidad_dia')->required()->multipliedBy($worked)), remunerative: false, computable: false, fifthCategory: false));
        }
    }
}
