<?php

namespace App\Modules\Contracts\Console;

use App\Modules\Contracts\Application\RemoteWorkService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * CON-6 · Aviso diario de solicitudes de teletrabajo sin responder cuyo plazo legal vence pronto, organización por
 * organización (teletrabajo.aviso_respuesta_dias). Cada solicitud se avisa una sola vez.
 */
final class RemindRemoteWorkResponses extends Command
{
    protected $signature = 'remote-work:remind-responses {--date= : Fecha de referencia (AAAA-MM-DD)}';

    protected $description = 'Avisa a RRHH de las solicitudes de teletrabajo por vencer';

    public function handle(TenantDirectory $tenants, RemoteWorkService $service): int
    {
        $date = is_string($this->option('date')) ? (string) $this->option('date') : now()->toDateString();
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenant) {
            $total += TenancyScope::runAs((string) $tenant, fn (): int => $service->remindDue($date));
        }
        $this->info((string) $total);

        return self::SUCCESS;
    }
}
