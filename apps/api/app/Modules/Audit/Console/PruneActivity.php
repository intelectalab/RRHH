<?php

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Application\RetentionPolicies;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AUD-3 · Retención de la actividad de usuarios (parámetro privacidad.retencion_actividad_dias),
 * independiente de la auditoría (que no se toca) y de los logs técnicos (los rota el canal daily).
 */
final class PruneActivity extends Command
{
    protected $signature = 'activity:prune';

    protected $description = 'Elimina la actividad de usuarios que superó su plazo de conservación';

    public function handle(TenantDirectory $tenants, RetentionPolicies $policies): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += (int) TenancyScope::runAs($tenantId, fn (): int => DB::table('activity_events')
                ->where('occurred_at', '<', now()->subDays($policies->activityDays()))->delete());
        }
        $this->info("Actividad eliminada: {$total}");

        return self::SUCCESS;
    }
}
