<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Application\NotificationDispatcher;
use App\Modules\Notifications\Console\PurgeNotifications;
use App\Modules\Notifications\Infrastructure\Subscribers\DeliverNotification;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\OutboxService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Notifier::class, NotificationDispatcher::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(OutboxService::class)->subscribe($this->app->make(DeliverNotification::class));

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeNotifications::class]);
            $this->app->booted(fn () => $this->app->make(Schedule::class)->command('notifications:archive-old')->dailyAt('03:10')->withoutOverlapping());
        }
    }
}
