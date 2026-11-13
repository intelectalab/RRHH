<?php

namespace App\Modules\CoreHR\Console;

use App\Modules\CoreHR\Application\EmploymentService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * RN-COL-04 · Los ingresos con fecha futura quedan «Por ingresar» y pasan a «Activa» el día del ingreso.
 * Corre cada día al empezar, organización por organización.
 */
final class ActivateDueEmployments extends Command
{
    protected $signature = 'employments:activate-due';

    protected $description = 'Activa los ingresos cuya fecha llegó (RN-COL-04)';

    public function handle(TenantDirectory $tenants, EmploymentService $employments): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $result = TenancyScope::runAs($tenantId, fn (): array => $employments->activateDue());
            $total += $result['activated'];
            foreach ($result['blocked'] as $item) {
                $this->warn((string) trans('people.activate_blocked', ['item' => $item]));
            }
        }
        $this->info((string) trans('people.activate_due', ['count' => $total]));

        return self::SUCCESS;
    }
}
