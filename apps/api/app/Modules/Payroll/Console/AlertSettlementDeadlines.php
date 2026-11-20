<?php

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Application\Settlements\Settlements;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * LIQ-3 · RN-LIQ-02 · Tarea horaria: avisa las liquidaciones sin pagar cuyo plazo legal de pago está por vencer o ya
 * venció (una vez por cálculo). Corre organización por organización.
 */
final class AlertSettlementDeadlines extends Command
{
    protected $signature = 'settlements:alert-deadlines';

    protected $description = 'Aviso del plazo de pago de las liquidaciones de beneficios sociales (LIQ-3)';

    public function handle(TenantDirectory $tenants, Settlements $settlements): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += (int) TenancyScope::runAs($tenantId, fn (): int => $settlements->alertDue());
        }
        $this->info((string) trans('payroll.settlements.alerted', ['count' => (string) $total]));

        return self::SUCCESS;
    }
}
