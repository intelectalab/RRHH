<?php

namespace App\Modules\Absences\Console;

use App\Modules\Absences\Application\AbsenceRequestService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * AUS-2 · Cada día: inicia las ausencias aprobadas que empiezan, finaliza las que terminaron y rechaza las
 * que vencieron sin sustento (RN-AUS-01). Organización por organización.
 */
final class AdvanceAbsences extends Command
{
    protected $signature = 'absences:advance {--date= : Fecha de referencia (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Inicia, finaliza y vence ausencias según sus fechas (AUS-2)';

    public function handle(TenantDirectory $tenants, AbsenceRequestService $absences): int
    {
        $date = $this->option('date');
        $today = is_string($date) && $date !== '' ? $date : null;
        $totals = ['started' => 0, 'ended' => 0, 'expired' => 0];
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $result = TenancyScope::runAs($tenantId, fn (): array => $absences->advance($today));
            foreach ($result as $k => $n) {
                $totals[$k] += $n;
            }
        }
        $this->info((string) trans('absences.requests.advanced', $totals));

        return self::SUCCESS;
    }
}
