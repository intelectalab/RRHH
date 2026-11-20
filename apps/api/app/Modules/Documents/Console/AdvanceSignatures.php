<?php

namespace App\Modules\Documents\Console;

use App\Modules\Documents\Application\SignatureService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * DOC-6 · Tarea diaria: vence las solicitudes de firma que pasaron su fecha y recuerda a quien tiene el turno
 * según firma.recordatorio_dias. Corre organización por organización.
 */
final class AdvanceSignatures extends Command
{
    protected $signature = 'signatures:advance';

    protected $description = 'Vence solicitudes de firma pasadas de fecha y envía recordatorios (DOC-6)';

    public function handle(TenantDirectory $tenants, SignatureService $signatures): int
    {
        $totals = ['expired' => 0, 'reminded' => 0];
        foreach ($tenants->operableTenantIds() as $tenantId) {
            foreach (TenancyScope::runAs($tenantId, fn (): array => $signatures->advance()) as $k => $n) {
                $totals[$k] += $n;
            }
        }
        $this->info((string) trans('documents.signatures.advance', $totals));

        return self::SUCCESS;
    }
}
