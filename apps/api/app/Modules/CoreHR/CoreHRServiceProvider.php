<?php

namespace App\Modules\CoreHR;

use App\Modules\CoreHR\Console\ActivateDueEmployments;
use App\Modules\CoreHR\Console\ApplyDuePersonnelActions;
use App\Modules\CoreHR\Console\ApplyDueTerminations;
use App\Modules\CoreHR\Console\ReviewDependentsMajority;
use App\Modules\CoreHR\Console\SendTeamKeyDateReminders;
use App\Modules\CoreHR\Infrastructure\Exports\EmploymentsExportSource;
use App\Modules\CoreHR\Infrastructure\Home\CelebrationsCard;
use App\Modules\CoreHR\Infrastructure\Home\ChangeRequestsCard;
use App\Modules\CoreHR\Infrastructure\Home\HeadcountCard;
use App\Modules\CoreHR\Infrastructure\Home\LaborCostCard;
use App\Modules\CoreHR\Infrastructure\Home\MovementsMonthCard;
use App\Modules\CoreHR\Infrastructure\Home\PendingStartCard;
use App\Modules\CoreHR\Infrastructure\Home\TurnoverCard;
use App\Modules\CoreHR\Infrastructure\Labor\AssignmentChangesAdapter;
use App\Modules\CoreHR\Infrastructure\Labor\MasterDataAdapter;
use App\Modules\CoreHR\Infrastructure\SelfService\ProfileHomeCard;
use App\Modules\CoreHR\Infrastructure\Setup\TerminationReasonsPackComponent;
use App\Modules\CoreHR\Infrastructure\Team\CelebrationTeamDates;
use App\Modules\CoreHR\Infrastructure\Usage\EmployeesUsageSource;
use App\Modules\CoreHR\Infrastructure\Workflow\PersonnelActionOutcome;
use App\Modules\CoreHR\Infrastructure\Workflow\SalaryChangeOutcome;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Home\HomeRegistry;
use App\Shared\Labor\AssignmentChanges;
use App\Shared\Labor\MasterData;
use App\Shared\SelfService\HomeCardRegistry;
use App\Shared\Setup\CountryPackRegistry;
use App\Shared\Team\TeamDateRegistry;
use App\Shared\Usage\UsageRegistry;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo COL · Colaboradores y ficha 360 (kit/docs/modulos/M-COL.md): personas, relaciones laborales,
 * ingreso y listado. Se comunica con Organización por los contratos de app/Shared/Organization.
 */
final class CoreHRServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CON-3 · RN-CON-08: las adendas firmadas registran el movimiento de la asignación sin importar Core HR.
        $this->app->bind(AssignmentChanges::class, AssignmentChangesAdapter::class);
        // HIS-6 · Servicio único de datos maestros vigentes a una fecha para los demás módulos.
        $this->app->bind(MasterData::class, MasterDataAdapter::class);
    }

    public function boot(): void
    {
        // IMP-4 · Parte del paquete de país que siembra este módulo.
        $this->app->make(CountryPackRegistry::class)->register($this->app->make(TerminationReasonsPackComponent::class));
        // SAS-6 · RN-COL-13 · Colaboradores activos frente al límite del plan.
        $this->app->make(UsageRegistry::class)->register(new EmployeesUsageSource);
        $this->app->make(ExportRegistry::class)->register($this->app->make(EmploymentsExportSource::class));
        // ESS-1 · Tarjeta «Mi perfil» del inicio del colaborador.
        $this->app->make(HomeCardRegistry::class)->register($this->app->make(ProfileHomeCard::class));
        // COL-12 · MSS-1 · Tarjetas de los tableros de inicio.
        $home = $this->app->make(HomeRegistry::class);
        foreach ([new HeadcountCard, MovementsMonthCard::hires(), MovementsMonthCard::terminations(), new PendingStartCard, CelebrationsCard::birthdays(), CelebrationsCard::anniversaries(), new LaborCostCard, new TurnoverCard, new ChangeRequestsCard] as $card) {
            $home->register($card);
        }
        // MSS-4 · Cumpleaños y aniversarios en las fechas clave del equipo.
        $this->app->make(TeamDateRegistry::class)->register(CelebrationTeamDates::birthdays());
        $this->app->make(TeamDateRegistry::class)->register(CelebrationTeamDates::anniversaries());
        // HIS-3 · WFL-4 · La decisión del flujo sobre un cambio de sueldo se aplica aquí al instante.
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(SalaryChangeOutcome::class));
        // HIS-2 · WFL-4 · La decisión del flujo sobre un movimiento de personal se aplica aquí al instante.
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(PersonnelActionOutcome::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ActivateDueEmployments::class, ApplyDueTerminations::class, ApplyDuePersonnelActions::class, ReviewDependentsMajority::class, SendTeamKeyDateReminders::class]);
            $this->app->booted(function (): void {
                // RN-COL-04 · Los ingresos de hoy se activan al empezar el día, después de los cambios de estructura.
                $this->app->make(Schedule::class)->command('employments:activate-due')->dailyAt('00:15')->withoutOverlapping();
                // COL-11 · Los ceses de hoy rigen antes de que se recalcule la ocupación de las posiciones (00:20).
                $this->app->make(Schedule::class)->command('employments:apply-terminations')->dailyAt('00:10')->withoutOverlapping();
                // HIS-4 · RN-HIS-04 · Los movimientos programados para hoy se aplican después de los ceses y antes
                // del recálculo de ocupación de las posiciones (00:20).
                $this->app->make(Schedule::class)->command('personnel-actions:apply-due')->dailyAt('00:12')->withoutOverlapping();
                // COL-6 · Hijos que cumplen la mayoría de edad: aviso de revisión a RRHH.
                $this->app->make(Schedule::class)->command('dependents:review-majority')->dailyAt('06:10')->withoutOverlapping();
                // MSS-4 · Recordatorios de cumpleaños y aniversarios del equipo a cada jefe.
                $this->app->make(Schedule::class)->command('team:key-date-reminders')->dailyAt('07:05')->withoutOverlapping();
            });
        }
    }
}
