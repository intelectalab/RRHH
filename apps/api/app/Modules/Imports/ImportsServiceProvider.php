<?php

namespace App\Modules\Imports;

use App\Modules\Imports\Infrastructure\Setup\DataStep;
use App\Modules\Imports\Infrastructure\Subscribers\RunImport;
use App\Shared\Outbox\OutboxService;
use App\Shared\Setup\SetupStepRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo IMP · Importador y puesta en marcha (kit/docs/modulos/M-IMP.md).
 * Los importadores los registra cada módulo dueño en App\Shared\Imports\ImportRegistry.
 */
final class ImportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(OutboxService::class)->subscribe($this->app->make(RunImport::class));

        // IMP-3 · Pasos de la puesta en marcha de Core HR: cada uno se da por hecho cuando su dato existe y
        // lleva a la pantalla real. Los módulos de fases posteriores registran los suyos.
        $steps = $this->app->make(SetupStepRegistry::class);
        $exists = fn (string $table): \Closure => fn (): bool => DB::table($table)->exists();
        $steps->register(new DataStep('company', 10, '/organizacion/empresas', fn (): bool => DB::table('legal_entities')->whereNotNull('tax_id')->exists()));
        $steps->register(new DataStep('country_pack', 20, '/configuracion/paquetes-pais', fn (): bool => DB::table('tenant_country_packs')->where('status', 'active')->exists()));
        $steps->register(new DataStep('sites', 30, '/organizacion/sedes', $exists('sites')));
        $steps->register(new DataStep('structure', 40, '/organizacion/unidades', $exists('org_units')));
        $steps->register(new DataStep('jobs', 50, '/organizacion/puestos', $exists('job_profiles')));
        $steps->register(new DataStep('employees', 60, '/personas/colaboradores', $exists('employments')));
        $steps->register(new DataStep('users', 70, '/configuracion/usuarios', fn (): bool => DB::table('users')->whereNull('deleted_at')->count() > 1));
        $steps->register(new DataStep('policies', 80, '/configuracion/politicas-vacaciones', $exists('vacation_policies'), 'time_off'));
        $steps->register(new DataStep('approval_flows', 90, '/configuracion/flujos', fn (): bool => DB::table('workflow_definitions')->whereNotNull('current_version_id')->whereNull('deleted_at')->exists()));
    }
}
