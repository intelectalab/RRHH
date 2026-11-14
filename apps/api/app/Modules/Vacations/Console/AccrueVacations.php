<?php

namespace App\Modules\Vacations\Console;

use App\Modules\Vacations\Application\VacationAccrual;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * VAC-2 · Cada día: gana los periodos que se completaron, actualiza el devengo del año en curso y marca
 * los vencidos (RN-VAC-09). Organización por organización y solo donde el módulo de vacaciones está activo.
 */
final class AccrueVacations extends Command
{
    protected $signature = 'vacations:accrue';

    protected $description = 'Pone al día los periodos vacacionales y marca los vencidos (VAC-2)';

    public function handle(TenantDirectory $tenants, VacationAccrual $accrual): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += (int) TenancyScope::runAs($tenantId, function () use ($accrual): int {
                if (! DB::table('tenant_modules')->where('module_code', 'time_off')->where('status', 'active')->exists()) {
                    return 0;
                }
                $ids = DB::table('employments')->whereNull('deleted_at')->whereIn('status', ['active', 'suspended', 'terminating'])->pluck('id');
                foreach ($ids as $id) {
                    $accrual->sync((string) $id);
                }

                return count($ids);
            });
        }
        $this->info((string) trans('vacations.accrued', ['count' => $total]));

        return self::SUCCESS;
    }
}
