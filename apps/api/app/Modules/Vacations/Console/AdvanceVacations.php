<?php

namespace App\Modules\Vacations\Console;

use App\Modules\Vacations\Application\VacationRequestService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * VAC-3 · Cada día: pone «en goce» las vacaciones aprobadas que empiezan (la reserva pasa a «tomadas») y
 * «gozadas» las que terminaron. Organización por organización; idempotente.
 */
final class AdvanceVacations extends Command
{
    protected $signature = 'vacations:advance {--date= : Fecha de referencia (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Inicia y finaliza el goce de vacaciones según sus fechas (VAC-3)';

    public function handle(TenantDirectory $tenants, VacationRequestService $requests): int
    {
        $date = $this->option('date');
        $today = is_string($date) && $date !== '' ? $date : null;
        $totals = ['started' => 0, 'finished' => 0];
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $result = TenancyScope::runAs($tenantId, fn (): array => $requests->advance($today));
            foreach ($result as $k => $n) {
                $totals[$k] += $n;
            }
        }
        $this->info((string) trans('vacations.requests.advanced', $totals));

        return self::SUCCESS;
    }
}
