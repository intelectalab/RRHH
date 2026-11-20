<?php

namespace App\Modules\Documents;

use App\Modules\Documents\Application\DocumentTemplateService;
use App\Modules\Documents\Application\SignatureService;
use App\Modules\Documents\Console\AdvanceSignatures;
use App\Modules\Documents\Console\CheckDocumentExpiry;
use App\Modules\Documents\Infrastructure\Calendar\DocumentCalendarSource;
use App\Modules\Documents\Infrastructure\Catalog\DocumentTypesAdapter;
use App\Modules\Documents\Infrastructure\Inbox\SignatureInboxSource;
use App\Modules\Documents\Infrastructure\SelfService\DocumentsHomeCard;
use App\Modules\Documents\Infrastructure\Setup\DocumentTemplatesPackComponent;
use App\Modules\Documents\Infrastructure\Subscribers\RunDocumentGeneration;
use App\Modules\Documents\Infrastructure\Trash\DocumentTrashSource;
use App\Modules\Documents\Infrastructure\Workflow\DocumentRequestOutcome;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Documents\DocumentTypes;
use App\Shared\Documents\TemplateRenderer;
use App\Shared\Inbox\InboxRegistry;
use App\Shared\Outbox\OutboxService;
use App\Shared\SelfService\HomeCardRegistry;
use App\Shared\Setup\CountryPackRegistry;
use App\Shared\Signatures\SignatureRequests;
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
        // ESS-4 · Los módulos que archivan en el legajo piden el tipo documental del sistema por su código.
        $this->app->bind(DocumentTypes::class, DocumentTypesAdapter::class);
        // DOC-6 · Otros módulos (renovación masiva de contratos) envían a firma por este puerto.
        $this->app->bind(SignatureRequests::class, SignatureService::class);
    }

    public function boot(): void
    {
        // IMP-4 · Parte del paquete de país que siembra este módulo.
        $this->app->make(CountryPackRegistry::class)->register($this->app->make(DocumentTemplatesPackComponent::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        // DOC-2 · Vencimientos de documentos en el calendario central.
        $this->app->make(CalendarRegistry::class)->register($this->app->make(DocumentCalendarSource::class));
        // DOC-1 · Los documentos subidos por error van a la papelera central.
        $this->app->make(TrashRegistry::class)->register($this->app->make(DocumentTrashSource::class));
        // DOC-3 · La revisión de lo entregado se decide en el motor de aprobaciones (flujo document_request).
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(DocumentRequestOutcome::class));
        // DOC-5 · Los lotes de generación se procesan en segundo plano.
        $this->app->make(OutboxService::class)->subscribe($this->app->make(RunDocumentGeneration::class));
        // ESS-3 · Mis documentos en el inicio del colaborador.
        $this->app->make(HomeCardRegistry::class)->register($this->app->make(DocumentsHomeCard::class));
        // DOC-6 · Documentos por firmar en la bandeja del firmante de turno.
        $this->app->make(InboxRegistry::class)->register($this->app->make(SignatureInboxSource::class));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckDocumentExpiry::class, AdvanceSignatures::class]);
            $this->app->booted(function (): void {
                // DOC-2 · RN-DOC-09 · Avisos de vencimiento y paso a Vencido, al empezar el día.
                $this->app->make(Schedule::class)->command('documents:check-expiry')->dailyAt('00:35')->withoutOverlapping();
                // DOC-6 · Firmas vencidas y recordatorios al firmante de turno.
                $this->app->make(Schedule::class)->command('signatures:advance')->dailyAt('08:10')->withoutOverlapping();
            });
        }
    }
}
