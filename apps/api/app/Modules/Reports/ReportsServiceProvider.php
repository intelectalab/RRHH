<?php

namespace App\Modules\Reports;

use App\Modules\Reports\Infrastructure\Subscribers\RunExportJob;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\ServiceProvider;

final class ReportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        // UI-11 · Las exportaciones grandes se generan en segundo plano.
        $this->app->make(OutboxService::class)->subscribe($this->app->make(RunExportJob::class));
    }
}
