<?php

namespace App\Modules\Documents\Console;

use App\Modules\Documents\Application\DocumentService;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * DOC-2 · RN-DOC-09 · Tarea diaria: avisa una vez los documentos que entran en la anticipación de su tipo y pasa a
 * Vencido los que llegaron a su fecha. Los avisos salen por el centro de notificaciones (outbox). Corre
 * organización por organización.
 */
final class CheckDocumentExpiry extends Command
{
    protected $signature = 'documents:check-expiry {--date= : Fecha a aplicar (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Avisos de vencimiento y paso a Vencido de los documentos del legajo (DOC-2)';

    public function handle(TenantDirectory $tenants, DocumentService $documents): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== '' ? $option : now()->toDateString();
        $totals = ['expiring' => 0, 'expired' => 0];
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $counts = TenancyScope::runAs($tenantId, fn (): array => $documents->checkExpiry($date));
            foreach ($counts as $k => $n) {
                $totals[$k] += $n;
            }
        }
        $this->info((string) trans('documents.documents.check_expiry', $totals));

        return self::SUCCESS;
    }
}
