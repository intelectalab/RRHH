<?php

namespace App\Modules\Documents;

use App\Modules\Documents\Application\DocumentTemplateService;
use App\Modules\Documents\Console\CheckDocumentExpiry;
use App\Modules\Documents\Infrastructure\Calendar\DocumentCalendarSource;
use App\Modules\Documents\Infrastructure\Subscribers\RunDocumentGeneration;
use App\Modules\Documents\Infrastructure\Trash\DocumentTrashSource;
use App\Modules\Documents\Infrastructure\Workflow\DocumentRequestOutcome;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Documents\TemplateRenderer;
use App\Shared\Outbox\OutboxService;
use App\Shared\Trash\TrashRegistry;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo DOC · Legajo digital: carpetas, documentos, versiones, archivo y vencimientos (kit/docs/modulos/M-DOC.md).
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // DOC-4 · Motor de plantillas para otros módulos (CON-2 genera el contrato desde la plantilla del tipo).
        $this->app->bind(TemplateRenderer::class, DocumentTemplateService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        // DOC-2 · Vencimientos de documentos en el calendario central.
        $this->app->make(CalendarRegistry::class)->register($this->app->make(DocumentCalendarSource::class));
        // DOC-1 · Los documentos subidos por error van a la papelera central.
        $this->app->make(TrashRegistry::class)->register($this->app->make(DocumentTrashSource::class));
        // DOC-3 · La revisión de lo entregado se decide en el motor de aprobaciones (flujo document_request).
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(DocumentRequestOutcome::class));
        // DOC-5 · Los lotes de generación se procesan en segundo plano.
        $this->app->make(OutboxService::class)->subscribe($this->app->make(RunDocumentGeneration::class));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckDocumentExpiry::class]);
            $this->app->booted(function (): void {
                // DOC-2 · RN-DOC-09 · Avisos de vencimiento y paso a Vencido, al empezar el día.
                $this->app->make(Schedule::class)->command('documents:check-expiry')->dailyAt('00:35')->withoutOverlapping();
            });
        }
    }
}
