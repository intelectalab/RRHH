<?php

namespace App\Modules\Audit\Console;

use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Trash\TrashRegistry;
use Illuminate\Console\Command;

/**
 * CFG-9 · Depuración definitiva de la papelera al vencer papelera.dias_restauracion de cada
 * organización (RN-CFG-09). Un registro que otros datos todavía referencian se conserva.
 */
final class PurgeTrash extends Command
{
    protected $signature = 'trash:purge';

    protected $description = 'Elimina definitivamente los registros que superaron su plazo en la papelera';

    public function handle(TenantDirectory $tenants, TrashRegistry $registry, ParameterResolver $parameters): int
    {
        $purged = 0;
        $kept = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            [$p, $k] = TenancyScope::runAs($tenantId, function () use ($registry, $parameters): array {
                $days = $parameters->value('papelera.dias_restauracion');
                if (! is_numeric($days)) {
                    return [0, 0];
                }
                $before = now()->subDays((int) $days);
                $p = 0;
                $k = 0;
                foreach ($registry->all() as $source) {
                    foreach ($source->expired($before) as $id) {
                        $source->purge($id) ? $p++ : $k++;
                    }
                }

                return [$p, $k];
            });
            $purged += $p;
            $kept += $k;
        }
        $this->info("Depurados: {$purged} · conservados por estar referenciados: {$kept}");

        return self::SUCCESS;
    }
}
