<?php

namespace App\Modules\SelfService;

use Illuminate\Support\ServiceProvider;

/**
 * Módulo ESS · Portal del colaborador (kit/docs/modulos/M-ESS.md). Módulo comercial core.
 *
 * No tiene datos propios: reúne lo de cada módulo por contratos de app/Shared (HomeCard, InboxSource) y
 * siempre con la relación laboral propia del usuario (RN-ESS-01).
 */
final class SelfServiceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
