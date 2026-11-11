<?php

namespace App\Modules\Organization;

use App\Modules\Organization\Infrastructure\Exports\LegalEntitiesExportSource;
use App\Modules\Organization\Infrastructure\Search\LegalEntitySearchSource;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Search\SearchRegistry;
use Illuminate\Support\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(SearchRegistry::class)
            ->register($this->app->make(LegalEntitySearchSource::class));

        $this->app->make(ExportRegistry::class)->register($this->app->make(LegalEntitiesExportSource::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
