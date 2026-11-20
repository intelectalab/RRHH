<?php

namespace App\Modules\Helpdesk;

use App\Modules\Helpdesk\Infrastructure\Help\HelpEscalationRequests;
use App\Modules\Helpdesk\Infrastructure\Inbox\TicketInboxSource;
use App\Modules\Helpdesk\Infrastructure\SelfService\TicketsHomeCard;
use App\Shared\Help\HrRequests;
use App\Shared\Inbox\InboxRegistry;
use App\Shared\SelfService\HomeCardRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo ESS-5 · Solicitudes a RRHH desde el portal (en el corte comercial, dentro de core). La mesa de
 * ayuda (HSD, fase 6) lo amplía después con colas, SLA por tipo y base de conocimiento.
 */
final class HelpdeskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AYU-6 · El centro de ayuda deriva consultas a RRHH como solicitudes.
        $this->app->bind(HrRequests::class, HelpEscalationRequests::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(HomeCardRegistry::class)->register($this->app->make(TicketsHomeCard::class));
        $this->app->make(InboxRegistry::class)->register($this->app->make(TicketInboxSource::class));
    }
}
