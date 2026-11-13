<?php

namespace App\Modules\CoreHR\Console;

use App\Modules\CoreHR\Application\TerminationService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * COL-11 · ME-01 · Un cese registrado con fecha futura deja la relación «En proceso de cese»; el día del
 * cese pasa a «Cesada» (hacer efectivo). Corre cada día al empezar, organización por organización.
 */
final class ApplyDueTerminations extends Command
{
    protected $signature = 'employments:apply-terminations';

    protected $description = 'Hace efectivos los ceses cuya fecha llegó (ME-01)';

    public function handle(TenantDirectory $tenants, TerminationService $terminations): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $result = TenancyScope::runAs($tenantId, fn (): array => $terminations->applyDue());
            $total += $result['applied'];
            foreach ($result['blocked'] as $item) {
                $this->warn((string) trans('people.termination.apply_blocked', ['item' => $item]));
            }
        }
        $this->info((string) trans('people.termination.applied', ['count' => $total]));

        return self::SUCCESS;
    }
}
