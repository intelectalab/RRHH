<?php

namespace App\Providers;

use App\Shared\Cache\TenantCache;
use App\Shared\Files\Contracts\FileStorageService;
use App\Shared\Files\FileService;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\OutboxService;
use App\Shared\Outbox\ProcessedEventTracker;
use App\Shared\Tenancy\Queue\TenantQueueContext;
use Illuminate\Database\Eloquent\Model;
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
    }
}
