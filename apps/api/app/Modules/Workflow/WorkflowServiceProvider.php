<?php

namespace App\Modules\Workflow;

use App\Modules\Workflow\Console\ProcessOverdueApprovals;
use App\Modules\Workflow\Console\RetryFailedAutomations;
use App\Modules\Workflow\Infrastructure\Inbox\ApprovalInboxSource;
use App\Modules\Workflow\Infrastructure\Inbox\TaskInboxSource;
use App\Modules\Workflow\Infrastructure\Subscribers\NotifyWorkflowParticipants;
use App\Modules\Workflow\Infrastructure\Subscribers\RunAutomationRules;
use App\Shared\Inbox\InboxRegistry;
use App\Shared\Outbox\OutboxService;
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

        $outbox = $this->app->make(OutboxService::class);
        $outbox->subscribe($this->app->make(NotifyWorkflowParticipants::class));
        // WFL-5 · Reglas CUANDO → SI → ENTONCES.
        $outbox->subscribe($this->app->make(RunAutomationRules::class));

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessOverdueApprovals::class, RetryFailedAutomations::class]);
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('workflow:process-overdue')->everyFifteenMinutes()->withoutOverlapping();
                $schedule->command('workflow:retry-automations')->everyFiveMinutes()->withoutOverlapping();
            });
        }
    }
}
