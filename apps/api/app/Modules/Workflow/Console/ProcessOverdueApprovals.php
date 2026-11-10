<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Application\ApprovalEngine;
use App\Modules\Workflow\Application\TaskService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * RN-WFL-06 · Recordatorios y escalamiento de aprobaciones vencidas y aviso de tareas de RRHH vencidas (WFL-8), organización por organización.
 */
final class ProcessOverdueApprovals extends Command
{
    protected $signature = 'workflow:process-overdue';

    protected $description = 'Envía recordatorios y escala las aprobaciones vencidas';

    public function handle(TenantDirectory $tenants, ApprovalEngine $engine, TaskService $tasks): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += (int) TenancyScope::runAs($tenantId, fn () => $engine->processOverdue() + $tasks->notifyOverdue());
        }
        $this->info("Tareas procesadas: {$total}");

        return self::SUCCESS;
    }
}
