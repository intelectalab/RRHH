<?php

namespace App\Modules\Absences;

use Illuminate\Support\ServiceProvider;

/**
 * Módulo AUS · Ausencias y permisos (kit/docs/modulos/M-AUS.md). Módulo comercial time_off.
 */
final class AbsencesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
