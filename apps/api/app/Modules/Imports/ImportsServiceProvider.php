<?php

namespace App\Modules\Imports;

use App\Modules\Imports\Infrastructure\Subscribers\RunImport;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo IMP · Importador y puesta en marcha (kit/docs/modulos/M-IMP.md).
 * Los importadores los registra cada módulo dueño en App\Shared\Imports\ImportRegistry.
 */
final class ImportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(OutboxService::class)->subscribe($this->app->make(RunImport::class));
    }
}
