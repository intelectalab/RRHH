<?php

namespace App\Modules\CoreHR\Console;

use App\Modules\CoreHR\Application\PersonnelActions;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * HIS-4 · RN-HIS-04 · Los movimientos programados se aplican solos el día de su fecha efectiva.
 * Corre cada día al empezar, organización por organización.
 */
final class ApplyDuePersonnelActions extends Command
{
    protected $signature = 'personnel-actions:apply-due';

    protected $description = 'Aplica los movimientos de personal programados cuya fecha llegó (RN-HIS-04)';

    public function handle(TenantDirectory $tenants, PersonnelActions $actions): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $result = TenancyScope::runAs($tenantId, fn (): array => $actions->applyDue());
            $total += $result['applied'];
            foreach ($result['blocked'] as $id) {
                $this->warn((string) trans('people.movements.apply_blocked', ['id' => $id]));
            }
        }
        $this->info((string) trans('people.movements.apply_due', ['count' => $total]));

        return self::SUCCESS;
    }
}
