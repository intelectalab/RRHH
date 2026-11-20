<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Application\AutomationRunner;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * RN-WFL-10 · Reintenta las ejecuciones de reglas que fallaron y cuyo plazo de reintento venció.
 */
final class RetryFailedAutomations extends Command
{
    protected $signature = 'workflow:retry-automations';

    protected $description = 'Reintenta las ejecuciones fallidas de reglas de automatización';

    public function handle(TenantDirectory $tenants, AutomationRunner $runner): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            // WFL-6 · También retoma los flujos del constructor cuya espera venció.
            $total += (int) TenancyScope::runAs($tenantId, fn () => $runner->retryDue() + $runner->resumeDue());
        }
        $this->info("Ejecuciones reintentadas: {$total}");

        return self::SUCCESS;
    }
}
