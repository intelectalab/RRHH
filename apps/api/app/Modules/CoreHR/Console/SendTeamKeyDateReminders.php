<?php

namespace App\Modules\CoreHR\Console;

use App\Modules\CoreHR\Application\TeamKeyDates;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * MSS-4 · Recordatorios al jefe de los cumpleaños y aniversarios de su equipo, organización por organización.
 * Se puede correr varias veces el mismo día: no repite avisos.
 */
final class SendTeamKeyDateReminders extends Command
{
    protected $signature = 'team:key-date-reminders {--date= : Fecha de corte (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Avisa a cada jefe de los cumpleaños y aniversarios próximos de su equipo (MSS-4)';

    public function handle(TenantDirectory $tenants, TeamKeyDates $dates): int
    {
        $date = $this->option('date');
        $day = is_string($date) && $date !== '' ? $date : now()->toDateString();
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += TenancyScope::runAs($tenantId, fn (): int => $dates->remind($day));
        }
        $this->info((string) trans('people.team.reminders_sent', ['count' => $total]));

        return self::SUCCESS;
    }
}
