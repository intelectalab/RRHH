<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Application\Messaging\MessageService;
use App\Modules\Notifications\Application\NotificationDispatcher;
use App\Modules\Notifications\Console\PurgeNotifications;
use App\Modules\Notifications\Infrastructure\Subscribers\DeliverMessage;
use App\Modules\Notifications\Infrastructure\Subscribers\DeliverNotification;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyAbsenceSubsidy;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyBilling;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyContractEvents;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyDocumentEvents;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyEmailBounce;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyLegalUpdates;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyOrganizationStatus;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyPeopleChanges;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifySignatures;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyTeamKeyDates;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyUsageLimit;
use App\Modules\Notifications\Infrastructure\Subscribers\SubmitTemplateApproval;
use App\Modules\Notifications\Infrastructure\Subscribers\VerifyEmailSender;
use App\Shared\Messaging\MessageSender;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\OutboxService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Notifier::class, NotificationDispatcher::class);
        $this->app->bind(MessageSender::class, MessageService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $outbox = $this->app->make(OutboxService::class);
        $outbox->subscribe($this->app->make(DeliverNotification::class));
        $outbox->subscribe($this->app->make(DeliverMessage::class));
        $outbox->subscribe($this->app->make(SubmitTemplateApproval::class));
        $outbox->subscribe($this->app->make(NotifyOrganizationStatus::class));
        $outbox->subscribe($this->app->make(NotifyUsageLimit::class));
        $outbox->subscribe($this->app->make(NotifyBilling::class));
        $outbox->subscribe($this->app->make(NotifyLegalUpdates::class));
        $outbox->subscribe($this->app->make(NotifySignatures::class));
        $outbox->subscribe($this->app->make(VerifyEmailSender::class));
        $outbox->subscribe($this->app->make(NotifyEmailBounce::class));
        $outbox->subscribe($this->app->make(NotifyPeopleChanges::class));
        $outbox->subscribe($this->app->make(NotifyContractEvents::class));
        // MSS-4 · Recordatorios de cumpleaños y aniversarios del equipo al jefe.
        $outbox->subscribe($this->app->make(NotifyTeamKeyDates::class));
        $outbox->subscribe($this->app->make(NotifyAbsenceSubsidy::class));
        $outbox->subscribe($this->app->make(NotifyDocumentEvents::class));

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeNotifications::class]);
            $this->app->booted(fn () => $this->app->make(Schedule::class)->command('notifications:archive-old')->dailyAt('03:10')->withoutOverlapping());
        }
    }
}
