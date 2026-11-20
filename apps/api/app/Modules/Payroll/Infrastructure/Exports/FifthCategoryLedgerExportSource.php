<?php

namespace App\Modules\Payroll\Infrastructure\Exports;

use App\Modules\Payroll\Application\FifthCategoryLedger;
use App\Shared\Exports\ExportSource;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\Request;

/**
 * PER-11 · El libro de retenciones de quinta se exporta con el servicio común (UI-11): una fila por movimiento
 * del libro, con el alcance de quien exporta. Los montos son sensibles y quedan en el registro de accesos.
 */
final class FifthCategoryLedgerExportSource implements ExportSource
{
    public function __construct(private readonly FifthCategoryLedger $ledger) {}

    public function resource(): string
    {
        return 'fifth_category_ledger';
    }

    public function permission(): string
    {
        return 'payroll.fifth_category_ledger.view';
    }

    public function filters(): array
    {
        return ['fiscal_year', 'employment_id', 'period_month', 'legal_entity_id'];
    }

    public function build(array $filters, Request $request, EffectivePermissions $permissions, int $limit): array
    {
        $query = $this->ledger->query($filters, $request)->limit($limit + 1);
        $rows = $query->get();
        $truncated = $rows->count() > $limit;

        return [
            'columns' => ['fiscal_year', 'period_month', 'sequence', 'entry_kind', 'employee_code', 'person_name', 'legal_entity_name',
                'gross_annual_income', 'net_annual_income', 'annual_tax', 'prior_withholdings_deducted', 'divisor', 'withholding_amount', 'currency', 'engine_version', 'created_at'],
            'rows' => $rows->take($limit)->map(fn ($r): array => [
                (string) $r->fiscal_year, (string) $r->period_month, (string) $r->sequence, (string) trans('payroll.ledger.kind_'.$r->entry_kind),
                (string) $r->employee_code, trim((string) $r->person_name), (string) $r->legal_entity_name,
                (string) $r->gross_annual_income, (string) $r->net_annual_income, (string) $r->annual_tax, (string) $r->prior_withholdings_deducted,
                $r->divisor === null ? '' : (string) $r->divisor, (string) $r->withholding_amount, (string) $r->currency, (string) $r->engine_version,
                substr((string) $r->created_at, 0, 19),
            ])->values()->all(),
            'sensitive' => ['gross_annual_income', 'net_annual_income', 'annual_tax', 'withholding_amount'],
            'truncated' => $truncated,
        ];
    }
}
