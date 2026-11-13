<?php

namespace App\Modules\Workflow;

use App\Modules\Workflow\Console\ProcessOverdueApprovals;
use App\Modules\Workflow\Console\RetryFailedAutomations;
use App\Modules\Workflow\Infrastructure\Approvals\EngineApprovalRequests;
use App\Modules\Workflow\Infrastructure\Calendar\TaskCalendarSource;
use App\Modules\Workflow\Infrastructure\Inbox\ApprovalInboxSource;
use App\Modules\Workflow\Infrastructure\Inbox\TaskInboxSource;
use App\Modules\Workflow\Infrastructure\Subscribers\NotifyWorkflowParticipants;
use App\Modules\Workflow\Infrastructure\Subscribers\RunAutomationRules;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Inbox\InboxRegistry;
use App\Shared\Outbox\OutboxService;
use App\Shared\Workflow\ApprovalRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // POS-3 · Los módulos piden aprobaciones por el puerto, sin importar el motor.
        $this->app->bind(ApprovalRequests::class, EngineApprovalRequests::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        $inbox = $this->app->make(InboxRegistry::class);
        $inbox->register($this->app->make(ApprovalInboxSource::class));
        $inbox->register($this->app->make(TaskInboxSource::class));
        // CFG-8 · Tareas con fecha límite en el calendario central.
        $this->app->make(CalendarRegistry::class)->register($this->app->make(TaskCalendarSource::class));

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
