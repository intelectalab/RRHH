<?php

namespace App\Providers;

use App\Shared\Cache\TenantCache;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Documents\LinkedDocumentRegistry;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Features\FeatureFlags;
use App\Shared\Files\Contracts\FileStorageService;
use App\Shared\Files\FileService;
use App\Shared\Home\HomeRegistry;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Inbox\InboxRegistry;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\OutboxService;
use App\Shared\Outbox\ProcessedEventTracker;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Search\SearchRegistry;
use App\Shared\Security\SupportContext;
use App\Shared\SelfService\HomeCardRegistry;
use App\Shared\Setup\CountryPackRegistry;
use App\Shared\Setup\SetupStepRegistry;
use App\Shared\Signatures\SignatureSubjectRegistry;
use App\Shared\Team\TeamDateRegistry;
use App\Shared\Tenancy\Provisioning\ProvisioningRegistry;
use App\Shared\Tenancy\Queue\TenantQueueContext;
use App\Shared\Trash\TrashRegistry;
use App\Shared\Usage\Sources\ApiCallsUsageSource;
use App\Shared\Usage\Sources\StorageUsageSource;
use App\Shared\Usage\UsageRegistry;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessedEventTracker::class);
        $this->app->singleton(OutboxService::class);
        $this->app->bind(OutboxWriter::class, OutboxService::class);

        $this->app->singleton(FileService::class);
        $this->app->bind(FileStorageService::class, FileService::class);

        $this->app->singleton(TenantCache::class);
        $this->app->singleton(PermissionResolver::class);
        $this->app->singleton(SupportContext::class);
        $this->app->singleton(ModuleGate::class);
        $this->app->singleton(DataScope::class);
        $this->app->singleton(SearchRegistry::class);
        $this->app->singleton(InboxRegistry::class);
        // DOC-6 · Dueños de documentos que se firman con el flujo común.
        $this->app->singleton(SignatureSubjectRegistry::class);
        // ESS-1 · Tarjetas del inicio del colaborador que registra cada módulo.
        $this->app->singleton(HomeCardRegistry::class);
        $this->app->singleton(ExportRegistry::class);
        $this->app->singleton(ImportRegistry::class);
        $this->app->singleton(TrashRegistry::class);
        $this->app->singleton(CalendarRegistry::class);
        $this->app->singleton(HomeRegistry::class);
        $this->app->singleton(TeamDateRegistry::class);
        $this->app->singleton(CountryPackRegistry::class);
        $this->app->singleton(SetupStepRegistry::class);
        $this->app->singleton(LinkedDocumentRegistry::class);
        $this->app->singleton(ProvisioningRegistry::class);
        $this->app->singleton(ApprovalOutcomeRegistry::class);
        $this->app->singleton(UsageRegistry::class);
        $this->app->singleton(FeatureFlags::class);

        $this->app->singleton(IntegrationCallRecorder::class);
        $this->app->singleton(IntegrationManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        TenantQueueContext::register();

        // SAS-6 · Consumo que mide la plataforma misma: almacenamiento y llamadas a la API.
        $usage = $this->app->make(UsageRegistry::class);
        $usage->register(new StorageUsageSource);
        $usage->register($this->app->make(ApiCallsUsageSource::class));

        // Permisos y módulos se memoizan solo dentro de una petición: un cambio de rol aplica de inmediato.
        Event::listen(RequestHandled::class, function (): void {
            $this->app->make(PermissionResolver::class)->forget();
            $this->app->make(ModuleGate::class)->forget();
            $this->app->make(DataScope::class)->forget();
            $this->app->make(FeatureFlags::class)->forget();
        });
    }
}
