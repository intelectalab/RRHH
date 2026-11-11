<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Console\ArchiveAudit;
use App\Modules\Audit\Console\PruneActivity;
use App\Modules\Audit\Console\VerifyAuditIntegrity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditIntegrity::class, ArchiveAudit::class, PruneActivity::class]);
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('audit:verify-integrity')->dailyAt('02:40')->withoutOverlapping();
                $schedule->command('audit:archive')->dailyAt('03:30')->withoutOverlapping();
                $schedule->command('activity:prune')->dailyAt('03:20')->withoutOverlapping();
            });
        }
    }
}
