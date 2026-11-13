<?php

namespace App\Modules\Contracts;

use App\Modules\Contracts\Application\ContractTypeCatalog;
use App\Shared\Labor\ContractTypeSource;
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
    }
}
