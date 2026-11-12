<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Usage\TenantUsage;
use Illuminate\Console\Command;

/**
 * SAS-6 · Foto diaria del consumo de cada organización (historia de la consola) y aviso si cruzó un umbral.
 */
final class SnapshotUsageCommand extends Command
{
    protected $signature = 'usage:snapshot';

    protected $description = 'Mide el consumo de cada organización frente a los límites de su plan y guarda la foto del día';

    public function handle(TenantUsage $usage): int
    {
        $count = $usage->snapshotAll();
        $this->info((string) trans('platform.usage.snapshot_done', ['count' => $count]));

        return self::SUCCESS;
    }
}
