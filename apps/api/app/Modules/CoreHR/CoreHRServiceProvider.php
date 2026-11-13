<?php

namespace App\Modules\CoreHR;

use App\Modules\CoreHR\Console\ActivateDueEmployments;
use App\Modules\CoreHR\Console\ApplyDueTerminations;
use App\Modules\CoreHR\Console\ReviewDependentsMajority;
use App\Modules\CoreHR\Infrastructure\Exports\EmploymentsExportSource;
use App\Modules\CoreHR\Infrastructure\Usage\EmployeesUsageSource;
use App\Modules\CoreHR\Infrastructure\Workflow\SalaryChangeOutcome;
use App\Shared\Exports\ExportRegistry;
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
    public function boot(): void
    {
        // SAS-6 · RN-COL-13 · Colaboradores activos frente al límite del plan.
        $this->app->make(UsageRegistry::class)->register(new EmployeesUsageSource);
        $this->app->make(ExportRegistry::class)->register($this->app->make(EmploymentsExportSource::class));
        // HIS-3 · WFL-4 · La decisión del flujo sobre un cambio de sueldo se aplica aquí al instante.
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(SalaryChangeOutcome::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ActivateDueEmployments::class, ApplyDueTerminations::class, ReviewDependentsMajority::class]);
            $this->app->booted(function (): void {
                // RN-COL-04 · Los ingresos de hoy se activan al empezar el día, después de los cambios de estructura.
                $this->app->make(Schedule::class)->command('employments:activate-due')->dailyAt('00:15')->withoutOverlapping();
                // COL-11 · Los ceses de hoy rigen antes de que se recalcule la ocupación de las posiciones (00:20).
                $this->app->make(Schedule::class)->command('employments:apply-terminations')->dailyAt('00:10')->withoutOverlapping();
                // COL-6 · Hijos que cumplen la mayoría de edad: aviso de revisión a RRHH.
                $this->app->make(Schedule::class)->command('dependents:review-majority')->dailyAt('06:10')->withoutOverlapping();
            });
        }
    }
}
