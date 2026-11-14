<?php

namespace App\Modules\Vacations;

use App\Modules\Vacations\Application\VacationLedger;
use App\Modules\Vacations\Console\AccrueVacations;
use App\Modules\Vacations\Infrastructure\Subscribers\SyncVacationsOnEmploymentEvents;
use App\Shared\Outbox\OutboxService;
use App\Shared\Time\VacationBalances;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo VAC · Vacaciones (kit/docs/modulos/M-VAC.md). Módulo comercial time_off.
 * Publica el saldo por el contrato App\Shared\Time\VacationBalances (lo consumen las solicitudes, VAC-3).
 */
final class VacationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VacationBalances::class, VacationLedger::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(OutboxService::class)->subscribe($this->app->make(SyncVacationsOnEmploymentEvents::class));

        if ($this->app->runningInConsole()) {
            $this->commands([AccrueVacations::class]);
            $this->app->booted(function (): void {
                // Después de los ingresos (00:15) y ceses (00:10) del día.
                $this->app->make(Schedule::class)->command('vacations:accrue')->dailyAt('00:35')->withoutOverlapping();
            });
        }
    }
}
