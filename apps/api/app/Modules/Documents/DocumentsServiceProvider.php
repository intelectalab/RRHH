<?php

namespace App\Modules\Documents;

use App\Modules\Documents\Console\CheckDocumentExpiry;
use App\Modules\Documents\Infrastructure\Calendar\DocumentCalendarSource;
use App\Modules\Documents\Infrastructure\Catalog\DocumentTypesAdapter;
use App\Modules\Documents\Infrastructure\SelfService\DocumentsHomeCard;
use App\Modules\Documents\Infrastructure\Trash\DocumentTrashSource;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Documents\DocumentTypes;
use App\Shared\SelfService\HomeCardRegistry;
use App\Shared\Trash\TrashRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo DOC · Legajo digital: carpetas, documentos, versiones, archivo y vencimientos (kit/docs/modulos/M-DOC.md).
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ESS-4 · Los módulos que archivan en el legajo piden el tipo documental del sistema por su código.
        $this->app->bind(DocumentTypes::class, DocumentTypesAdapter::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        // DOC-2 · Vencimientos de documentos en el calendario central.
        $this->app->make(CalendarRegistry::class)->register($this->app->make(DocumentCalendarSource::class));
        // DOC-1 · Los documentos subidos por error van a la papelera central.
        $this->app->make(TrashRegistry::class)->register($this->app->make(DocumentTrashSource::class));
        // ESS-3 · Mis documentos en el inicio del colaborador.
        $this->app->make(HomeCardRegistry::class)->register($this->app->make(DocumentsHomeCard::class));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckDocumentExpiry::class]);
            $this->app->booted(function (): void {
                // DOC-2 · RN-DOC-09 · Avisos de vencimiento y paso a Vencido, al empezar el día.
                $this->app->make(Schedule::class)->command('documents:check-expiry')->dailyAt('00:35')->withoutOverlapping();
            });
        }
    }
}
