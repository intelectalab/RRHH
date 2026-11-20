<?php

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Application\Payslips\Payslips;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * BOL-2 · RN-BOL-03 · Tarea diaria: recuerda el acuse a quien tiene boletas publicadas pendientes de acuse
 * (solo cuando la empresa lo exige). Corre organización por organización.
 */
final class RemindPayslipAcknowledgement extends Command
{
    protected $signature = 'payslips:remind-acknowledgement';

    protected $description = 'Recordatorio diario del acuse de boletas pendientes (BOL-2)';

    public function handle(TenantDirectory $tenants, Payslips $payslips): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += (int) TenancyScope::runAs($tenantId, fn (): int => $payslips->remindPending());
        }
        $this->info((string) trans('payroll.payslips.reminded', ['count' => (string) $total]));

        return self::SUCCESS;
    }
}
