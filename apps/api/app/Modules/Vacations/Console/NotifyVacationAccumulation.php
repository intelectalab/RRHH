<?php

namespace App\Modules\Vacations\Console;

use App\Modules\Vacations\Application\VacationScheduling;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * VAC-5 · RN-VAC-09 · RN-VAC-10: avisa al jefe y a RRHH, una sola vez por periodo, de los periodos vacacionales
 * vencidos o por vencer (vacaciones.aviso_vencimiento_dias) en cada organización con el módulo activo.
 */
final class NotifyVacationAccumulation extends Command
{
    protected $signature = 'vacations:accumulation-alerts';

    protected $description = 'Avisa de periodos de vacaciones vencidos o por vencer (una vez por periodo)';

    public function handle(TenantDirectory $tenants, VacationScheduling $scheduling): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += (int) TenancyScope::runAs($tenantId, function () use ($scheduling): int {
                if (! DB::table('tenant_modules')->where('module_code', 'time_off')->where('status', 'active')->exists()) {
                    return 0;
                }

                return $scheduling->notifyAlerts(now()->toDateString());
            });
        }
        $this->info((string) trans('vacations.alerts.sent', ['count' => $total]));

        return self::SUCCESS;
    }
}
