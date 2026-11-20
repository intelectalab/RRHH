<?php

namespace App\Modules\Vacations;

use App\Modules\Vacations\Application\VacationAgreements;
use App\Modules\Vacations\Application\VacationLedger;
use App\Modules\Vacations\Console\AccrueVacations;
use App\Modules\Vacations\Console\AdvanceVacations;
use App\Modules\Vacations\Console\NotifyVacationAccumulation;
use App\Modules\Vacations\Infrastructure\Calendar\VacationCalendarSource;
use App\Modules\Vacations\Infrastructure\Exports\VacationScheduleExportSource;
use App\Modules\Vacations\Infrastructure\Home\VacationsCard;
use App\Modules\Vacations\Infrastructure\Imports\VacationBalancesImporter;
use App\Modules\Vacations\Infrastructure\SelfService\VacationsHomeCard;
use App\Modules\Vacations\Infrastructure\Setup\VacationPoliciesPackComponent;
use App\Modules\Vacations\Infrastructure\Subscribers\SyncVacationsOnEmploymentEvents;
use App\Modules\Vacations\Infrastructure\Workflow\VacationRequestOutcome;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Home\HomeRegistry;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Outbox\OutboxService;
use App\Shared\SelfService\HomeCardRegistry;
use App\Shared\Setup\CountryPackRegistry;
use App\Shared\Time\VacationBalances;
use App\Shared\Time\VacationPayrollIncidents;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo VAC · Vacaciones (kit/docs/modulos/M-VAC.md). Módulo comercial time_off.
 * Publica el saldo por el contrato App\Shared\Time\VacationBalances (lo consumen las solicitudes, VAC-3),
 * las solicitudes con aprobación (VAC-3) y el calendario de ausencias del equipo (VAC-4).
 */
final class VacationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VacationBalances::class, VacationLedger::class);
        // VAC-6 · La planilla lee las ventas de días con acuerdo firmado.
        $this->app->bind(VacationPayrollIncidents::class, VacationAgreements::class);
    }

    public function boot(): void
    {
        // IMP-4 · Parte del paquete de país que siembra este módulo.
        $this->app->make(CountryPackRegistry::class)->register($this->app->make(VacationPoliciesPackComponent::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(OutboxService::class)->subscribe($this->app->make(SyncVacationsOnEmploymentEvents::class));
        // VAC-3 · El motor de aprobaciones devuelve aquí la decisión sobre una solicitud de vacaciones.
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(VacationRequestOutcome::class));
        // VAC-3 · VAC-4 · Las vacaciones aprobadas aparecen en el calendario del equipo (CFG-8).
        $this->app->make(CalendarRegistry::class)->register($this->app->make(VacationCalendarSource::class));
        // ESS-1 · Tarjeta «Mis vacaciones» del inicio del colaborador.
        $this->app->make(HomeCardRegistry::class)->register($this->app->make(VacationsHomeCard::class));
        $this->app->make(HomeRegistry::class)->register(new VacationsCard);
        // VAC-7 · Saldos iniciales por periodo vacacional (movimientos de apertura del libro).
        $this->app->make(ImportRegistry::class)->register($this->app->make(VacationBalancesImporter::class));
        // VAC-5 · El rol anual se exporta con el servicio común.
        $this->app->make(ExportRegistry::class)->register($this->app->make(VacationScheduleExportSource::class));

        if ($this->app->runningInConsole()) {
            $this->commands([AccrueVacations::class, AdvanceVacations::class, NotifyVacationAccumulation::class]);
            $this->app->booted(function (): void {
                // Después de los ingresos (00:15) y ceses (00:10) del día.
                $this->app->make(Schedule::class)->command('vacations:accrue')->dailyAt('00:35')->withoutOverlapping();
                // VAC-3 · Después del devengo: inicia y finaliza el goce de las vacaciones aprobadas.
                $this->app->make(Schedule::class)->command('vacations:advance')->dailyAt('00:40')->withoutOverlapping();
                // VAC-5 · Avisos de periodos vencidos o por vencer, una vez por periodo.
                $this->app->make(Schedule::class)->command('vacations:accumulation-alerts')->dailyAt('07:20')->withoutOverlapping();
            });
        }
    }
}
