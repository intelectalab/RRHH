<?php

namespace App\Modules\Contracts;

use Illuminate\Support\ServiceProvider;

/**
 * Módulo CON · Contratos y adendas (kit/docs/modulos/M-CON.md).
 */
final class ContractsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
