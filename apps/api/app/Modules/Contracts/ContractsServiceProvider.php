<?php

namespace App\Modules\Contracts;

use App\Modules\Contracts\Application\ContractTypeCatalog;
use App\Modules\Contracts\Console\ApplyDueContracts;
use App\Modules\Contracts\Infrastructure\Calendar\ContractCalendarSource;
use App\Modules\Contracts\Infrastructure\Home\ContractDatesCard;
use App\Modules\Contracts\Infrastructure\Team\ContractTeamDates;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Home\HomeRegistry;
use App\Shared\Labor\ContractTypeSource;
use App\Shared\Team\TeamDateRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo CON · Contratos y adendas (kit/docs/modulos/M-CON.md).
 */
final class ContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // COL-2 · El ingreso pide la precarga de tipos de contrato sin importar este módulo.
        $this->app->singleton(ContractTypeSource::class, ContractTypeCatalog::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        // CON-4 · Vencimientos de contratos en el calendario central.
        $this->app->make(CalendarRegistry::class)->register($this->app->make(ContractCalendarSource::class));
        $this->app->make(HomeRegistry::class)->register(ContractDatesCard::expiring());
        $this->app->make(HomeRegistry::class)->register(ContractDatesCard::probation());
        // MSS-4 · Fin del periodo de prueba y del contrato en las fechas clave del equipo.
        $this->app->make(TeamDateRegistry::class)->register(ContractTeamDates::probation());
        $this->app->make(TeamDateRegistry::class)->register(ContractTeamDates::expiry());

        if ($this->app->runningInConsole()) {
            $this->commands([ApplyDueContracts::class]);
            $this->app->booted(function (): void {
                // CON-2 · CON-4 · Después de los ceses (00:10) y de los ingresos (00:15): inicio, vencimiento, aviso de prueba
                // y avisos de vencimiento y de tope acumulado.
                $this->app->make(Schedule::class)->command('contracts:apply-due')->dailyAt('00:25')->withoutOverlapping();
            });
        }
    }
}
