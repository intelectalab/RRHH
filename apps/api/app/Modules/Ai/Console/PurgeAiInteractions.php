<?php

namespace App\Modules\Ai\Console;

use App\Modules\Ai\Application\AiUsageLog;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * IAR-2 · Retención de la bitácora de IA: vacía el contenido cifrado vencido de cada organización según
 * ia.retencion_bitacora_dias. La fila (quién, cuándo, propósito y consumo) se conserva.
 */
final class PurgeAiInteractions extends Command
{
    protected $signature = 'ai:purge-interactions';

    protected $description = 'Vacía el contenido vencido de la bitácora de IA según la retención de cada organización (IAR-2)';

    public function handle(TenantDirectory $tenants, AiUsageLog $log): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += TenancyScope::runAs($tenantId, fn (): int => $log->purge());
        }
        $this->info((string) trans('ai.purged', ['count' => $total]));

        return self::SUCCESS;
    }
}
