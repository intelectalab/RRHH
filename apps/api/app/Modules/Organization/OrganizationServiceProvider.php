<?php

namespace App\Modules\Organization;

use App\Modules\Organization\Application\StructureAvailabilityService;
use App\Modules\Organization\Infrastructure\Exports\LegalEntitiesExportSource;
use App\Modules\Organization\Infrastructure\Imports\LegalEntitiesImporter;
use App\Modules\Organization\Infrastructure\Provisioning\CreateFirstLegalEntity;
use App\Modules\Organization\Infrastructure\Search\LegalEntitySearchSource;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Search\SearchRegistry;
use App\Shared\Tenancy\Provisioning\ProvisioningRegistry;
use Illuminate\Support\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StructureAvailability::class, StructureAvailabilityService::class);
    }

    public function boot(): void
    {
        $this->app->make(SearchRegistry::class)
            ->register($this->app->make(LegalEntitySearchSource::class));

        $this->app->make(ExportRegistry::class)->register($this->app->make(LegalEntitiesExportSource::class));
        $this->app->make(ImportRegistry::class)->register($this->app->make(LegalEntitiesImporter::class));
        $this->app->make(ProvisioningRegistry::class)->register($this->app->make(CreateFirstLegalEntity::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
