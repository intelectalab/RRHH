<?php

namespace App\Modules\Workflow;

use App\Modules\Workflow\Console\ProcessOverdueApprovals;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessOverdueApprovals::class]);
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)->command('workflow:process-overdue')->everyFifteenMinutes()->withoutOverlapping();
            });
        }
    }
}
