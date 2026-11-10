<?php

namespace App\Modules\Workflow;

use App\Modules\Workflow\Console\ProcessOverdueApprovals;
use App\Modules\Workflow\Infrastructure\Inbox\ApprovalInboxSource;
use App\Modules\Workflow\Infrastructure\Inbox\TaskInboxSource;
use App\Shared\Inbox\InboxRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        $inbox = $this->app->make(InboxRegistry::class);
        $inbox->register($this->app->make(ApprovalInboxSource::class));
        $inbox->register($this->app->make(TaskInboxSource::class));

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessOverdueApprovals::class]);
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)->command('workflow:process-overdue')->everyFifteenMinutes()->withoutOverlapping();
            });
        }
    }
}
