<?php

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Application\AuditArchiver;
use App\Shared\Tenancy\CrossTenantMaintenance;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * AUD-5 · Retención de la auditoría: archiva en frío los meses fuera de línea, retira las
 * particiones ya archivadas por todas las organizaciones y borra los archivos con plazo vencido.
 */
final class ArchiveAudit extends Command
{
    protected $signature = 'audit:archive';

    protected $description = 'Archiva en frío la auditoría antigua y aplica su plazo de conservación';

    public function handle(TenantDirectory $tenants, AuditArchiver $archiver, CrossTenantMaintenance $maintenance): int
    {
        $months = 0;
        $purged = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            [$a, $p] = TenancyScope::runAs($tenantId, fn (): array => [$archiver->archiveDue(), $archiver->purgeExpired()]);
            $months += $a;
            $purged += $p;
        }
        $dropped = $maintenance->withoutTenant(fn (): array => $archiver->dropArchivedPartitions());
        $this->info(sprintf('Meses archivados: %d · particiones retiradas: %d · archivos vencidos borrados: %d', $months, count($dropped), $purged));

        return self::SUCCESS;
    }
}
