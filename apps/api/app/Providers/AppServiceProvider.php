<?php

namespace App\Providers;

use App\Shared\Cache\TenantCache;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Files\Contracts\FileStorageService;
use App\Shared\Files\FileService;
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
use App\Shared\Tenancy\Queue\TenantQueueContext;
use App\Shared\Trash\TrashRegistry;
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
        $this->app->singleton(ModuleGate::class);
        $this->app->singleton(DataScope::class);
        $this->app->singleton(SearchRegistry::class);
        $this->app->singleton(InboxRegistry::class);
        $this->app->singleton(ExportRegistry::class);
        $this->app->singleton(TrashRegistry::class);

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

        // Permisos y módulos se memoizan solo dentro de una petición: un cambio de rol aplica de inmediato.
        Event::listen(RequestHandled::class, function (): void {
            $this->app->make(PermissionResolver::class)->forget();
            $this->app->make(ModuleGate::class)->forget();
            $this->app->make(DataScope::class)->forget();
        });
    }
}
