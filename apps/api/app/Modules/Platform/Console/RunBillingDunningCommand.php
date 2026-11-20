<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Billing\SaasBilling;
use Illuminate\Console\Command;

/**
 * SAS-11 · Tarea diaria del cobro: aviso antes del vencimiento, comprobantes vencidos (suscripción en mora) y
 * suspensión tras los días de mora que fija la consola (billing.suspend_after_overdue_days).
 */
final class RunBillingDunningCommand extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Avisos de vencimiento, mora y suspensión por falta de pago de las suscripciones';

    public function handle(SaasBilling $billing): int
    {
        $r = $billing->runDunning();
        $this->info("Avisos: {$r['reminded']} · vencidos: {$r['overdue']} · suspendidas: {$r['suspended']}");

        return self::SUCCESS;
    }
}
