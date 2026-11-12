<?php

namespace App\Modules\Organization\Console;

use App\Modules\Organization\Application\OrgUnitService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * ORG-3 · Los cambios de estructura programados (mover, renombrar, cambiar responsable con fecha
 * futura) se aplican a la foto vigente de org_units el día que rigen, para que el alcance de datos,
 * el organigrama y los aprobadores se recalculen solos (RN-ORG-03). Idempotente.
 */
final class ApplyStructureChanges extends Command
{
    protected $signature = 'org:apply-structure-changes';

    protected $description = 'Aplica los cambios de estructura de unidades que empiezan a regir hoy';

    public function handle(TenantDirectory $tenants, OrgUnitService $units): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += TenancyScope::runAs($tenantId, fn (): int => $units->applyDueChanges());
        }
        $this->info("Unidades actualizadas: {$total}");

        return self::SUCCESS;
    }
}
