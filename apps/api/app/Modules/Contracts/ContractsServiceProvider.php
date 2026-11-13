<?php

namespace App\Modules\Contracts;

use App\Modules\Contracts\Application\ContractTypeCatalog;
use App\Modules\Contracts\Console\ApplyDueContracts;
use App\Shared\Labor\ContractTypeSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo CON · Contratos y adendas (kit/docs/modulos/M-CON.md).
 */
final class ContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // COL-2 · El ingreso pide la precarga de tipos de contrato sin importar este módulo.
        $this->app->singleton(ContractTypeSource::class, ContractTypeCatalog::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ApplyDueContracts::class]);
            $this->app->booted(function (): void {
                // CON-2 · Después de los ceses (00:10) y de los ingresos (00:15): inicio, vencimiento y aviso de prueba.
                $this->app->make(Schedule::class)->command('contracts:apply-due')->dailyAt('00:25')->withoutOverlapping();
            });
        }
    }
}
