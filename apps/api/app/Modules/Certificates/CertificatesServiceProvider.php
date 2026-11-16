<?php

namespace App\Modules\Certificates;

use App\Modules\Certificates\Infrastructure\Inbox\CertificateInboxSource;
use App\Modules\Certificates\Infrastructure\SelfService\CertificatesHomeCard;
use App\Modules\Certificates\Infrastructure\Workflow\CertificateRequestOutcome;
use App\Shared\Documents\CertificateTemplates;
use App\Shared\Documents\NoCertificateTemplates;
use App\Shared\Inbox\InboxRegistry;
use App\Shared\SelfService\HomeCardRegistry;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo ESS-4 · Constancias y certificados desde el portal (módulo comercial core).
 *
 * Arma la constancia con SimplePdf; cuando el módulo de plantillas (DOC-3/4/5) registre su propia
 * implementación de CertificateTemplates, esa toma el lugar de la de por defecto.
 */
final class CertificatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CertificateTemplates::class, NoCertificateTemplates::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(CertificateRequestOutcome::class));
        $this->app->make(HomeCardRegistry::class)->register($this->app->make(CertificatesHomeCard::class));
        $this->app->make(InboxRegistry::class)->register($this->app->make(CertificateInboxSource::class));
    }
}
