<?php

namespace App\Modules\Contracts\Console;

use App\Modules\Contracts\Application\ContractAlerts;
use App\Modules\Contracts\Application\ContractService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * CON-2 · ME-11 · Tarea diaria: los contratos firmados que empiezan pasan a Vigente (y el renovado, a
 * Terminado), los vencidos sin renovación ni cese pasan a «Vencido sin regularizar» (RN-CON-05) y se avisa
 * del fin del periodo de prueba. CON-4: avisos de vencimiento y de tope acumulado (RN-CON-04).
 * Corre organización por organización.
 */
final class ApplyDueContracts extends Command
{
    protected $signature = 'contracts:apply-due {--date= : Fecha a aplicar (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Inicio, vencimiento y aviso del fin del periodo de prueba de los contratos (CON-2)';

    public function handle(TenantDirectory $tenants, ContractService $contracts, ContractAlerts $alerts): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== '' ? $option : now()->toDateString();
        $totals = ['started' => 0, 'completed' => 0, 'expired' => 0, 'probation_alerts' => 0, 'expiry_alerts' => 0, 'cap_alerts' => 0];
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $counts = TenancyScope::runAs($tenantId, fn (): array => $contracts->applyDue($date) + $alerts->run($date));
            foreach ($counts as $k => $n) {
                $totals[$k] += $n;
            }
        }
        $this->info((string) trans('contracts.contracts.apply_due', $totals));

        return self::SUCCESS;
    }
}
