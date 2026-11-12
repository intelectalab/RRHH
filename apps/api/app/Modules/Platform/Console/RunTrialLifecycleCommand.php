<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

/**
 * SAS-4 · RN-SAS-04 · Tarea diaria: vence las pruebas sin conversión (la organización queda en solo
 * lectura durante la gracia) y suspende a las que terminaron la gracia sin contratar.
 */
final class RunTrialLifecycleCommand extends Command
{
    protected $signature = 'platform:subscriptions:trials';

    protected $description = 'Vence las pruebas gratuitas sin conversión y suspende al terminar el periodo de gracia';

    public function handle(SubscriptionService $subscriptions): int
    {
        $result = $subscriptions->runTrialLifecycle();
        $this->info("Pruebas vencidas: {$result['expired']} · organizaciones suspendidas: {$result['suspended']}");

        return self::SUCCESS;
    }
}
