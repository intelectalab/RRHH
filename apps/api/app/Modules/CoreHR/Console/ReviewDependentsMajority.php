<?php

namespace App\Modules\CoreHR\Console;

use App\Modules\CoreHR\Application\DependentService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * COL-6 · Los hijos derechohabientes que cumplieron la mayoría de edad pasan a revisión de RRHH (siguen,
 * estudian, tienen incapacidad o se dan de baja). Corre cada día, organización por organización.
 */
final class ReviewDependentsMajority extends Command
{
    protected $signature = 'dependents:review-majority {--date= : Fecha de corte (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Avisa a RRHH de los hijos derechohabientes que cumplieron la mayoría de edad (COL-6)';

    public function handle(TenantDirectory $tenants, DependentService $dependents): int
    {
        $date = $this->option('date');
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += TenancyScope::runAs($tenantId, fn (): int => $dependents->reviewMajority(is_string($date) ? $date : null));
        }
        $this->info((string) trans('people.dependents.majority_reviewed', ['count' => $total]));

        return self::SUCCESS;
    }
}
