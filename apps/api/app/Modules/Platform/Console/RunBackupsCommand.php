<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Backups\Backups;
use Illuminate\Console\Command;

/**
 * SAS-12 · Copia automática diaria de la plataforma, vencimiento por retención y prueba periódica de restauración
 * en aislado (deja evidencia). El almacenamiento es del proveedor (BackupStoragePort).
 */
final class RunBackupsCommand extends Command
{
    protected $signature = 'backups:run {--test-restore : Solo la prueba de restauración en aislado} {--force : Prueba la restauración aunque no toque}';

    protected $description = 'Copia automática de la plataforma, vencimiento de copias y prueba periódica de restauración';

    public function handle(Backups $backups): int
    {
        if (! $this->option('test-restore')) {
            $result = $backups->runScheduled();
            $this->info((string) trans('platform.backups.run_done', ['status' => $result['run'], 'expired' => $result['expired']]));
        }
        $test = $backups->scheduledTestRestore((bool) $this->option('force'));
        if ($test !== null) {
            $this->info((string) trans('platform.backups.test_done', ['status' => (string) $test['status']]));
        }

        return self::SUCCESS;
    }
}
