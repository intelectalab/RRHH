<?php

namespace App\Modules\Absences;

use App\Modules\Absences\Console\AdvanceAbsences;
use App\Modules\Absences\Infrastructure\Calendar\AbsenceCalendarSource;
use App\Modules\Absences\Infrastructure\Documents\AbsenceLinkedDocuments;
use App\Modules\Absences\Infrastructure\Time\ApprovedAbsencesQuery;
use App\Modules\Absences\Infrastructure\Workflow\AbsenceRequestOutcome;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Documents\LinkedDocumentRegistry;
use App\Shared\Time\ApprovedAbsences;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo AUS · Ausencias y permisos (kit/docs/modulos/M-AUS.md). Módulo comercial time_off.
 */
final class AbsencesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AUS-2 · Asistencia y planilla consultan las ausencias aprobadas sin importar este módulo.
        $this->app->bind(ApprovedAbsences::class, ApprovedAbsencesQuery::class);
    }

    public function boot(): void
    {
        // AUS-2 · WFL-4 · La decisión del flujo sobre una ausencia se aplica aquí al instante.
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(AbsenceRequestOutcome::class));
        // AUS-2 · Las ausencias aprobadas aparecen en el calendario del equipo (CFG-8).
        $this->app->make(CalendarRegistry::class)->register($this->app->make(AbsenceCalendarSource::class));
        // DOC-1 · Los sustentos y certificados de ausencias aparecen en el legajo sin copiar el archivo.
        $this->app->make(LinkedDocumentRegistry::class)->register($this->app->make(AbsenceLinkedDocuments::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([AdvanceAbsences::class]);
            $this->app->booted(function (): void {
                // AUS-2 · Inicia y finaliza ausencias y vence las que esperan sustento, al empezar el día.
                $this->app->make(Schedule::class)->command('absences:advance')->dailyAt('00:25')->withoutOverlapping();
            });
        }
    }
}
