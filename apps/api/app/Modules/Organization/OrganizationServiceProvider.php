<?php

namespace App\Modules\Organization;

use App\Modules\Organization\Application\PositionOccupancyService;
use App\Modules\Organization\Application\StructureAvailabilityService;
use App\Modules\Organization\Console\ApplyStructureChanges;
use App\Modules\Organization\Console\SyncPositionOccupancy;
use App\Modules\Organization\Infrastructure\Exports\LegalEntitiesExportSource;
use App\Modules\Organization\Infrastructure\Imports\LegalEntitiesImporter;
use App\Modules\Organization\Infrastructure\Provisioning\CreateFirstLegalEntity;
use App\Modules\Organization\Infrastructure\Search\LegalEntitySearchSource;
use App\Modules\Organization\Infrastructure\Usage\EmployeesUsageSource;
use App\Modules\Organization\Infrastructure\Usage\LegalEntitiesUsageSource;
use App\Modules\Organization\Infrastructure\Workflow\PositionRequestOutcome;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Search\SearchRegistry;
use App\Shared\Tenancy\Provisioning\ProvisioningRegistry;
use App\Shared\Usage\UsageRegistry;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StructureAvailability::class, StructureAvailabilityService::class);
        $this->app->bind(PositionOccupancy::class, PositionOccupancyService::class);
    }

    public function boot(): void
    {
        $this->app->make(SearchRegistry::class)
            ->register($this->app->make(LegalEntitySearchSource::class));

        $this->app->make(ExportRegistry::class)->register($this->app->make(LegalEntitiesExportSource::class));
        $this->app->make(ImportRegistry::class)->register($this->app->make(LegalEntitiesImporter::class));
        $this->app->make(ProvisioningRegistry::class)->register($this->app->make(CreateFirstLegalEntity::class));
        // SAS-6 · Consumo frente a los límites del plan.
        $this->app->make(UsageRegistry::class)->register(new LegalEntitiesUsageSource);
        $this->app->make(UsageRegistry::class)->register(new EmployeesUsageSource);
        // POS-3 · WFL-4 · La decisión del flujo sobre una solicitud de posición se aplica aquí al instante.
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(PositionRequestOutcome::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ApplyStructureChanges::class, SyncPositionOccupancy::class]);
            $this->app->booted(function (): void {
                // ORG-3 · Cambios de estructura con fecha: rigen desde el inicio del día.
                $this->app->make(Schedule::class)->command('org:apply-structure-changes')->dailyAt('00:10')->withoutOverlapping();
                // POS-2 · RN-POS-04 · Ocupada / vacante según las asignaciones que empiezan o terminan hoy.
                $this->app->make(Schedule::class)->command('positions:sync-occupancy')->dailyAt('00:20')->withoutOverlapping();
            });
        }
    }
}
