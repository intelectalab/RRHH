<?php

namespace App\Modules\HelpCenter;

use App\Shared\Help\HelpScreens;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo AYU · Centro de ayuda de la organización (core). La ayuda del producto se redacta y publica en la
 * consola (Platform); aquí solo se lee la última versión publicada con las reglas de visibilidad RN-AYU-01.
 */
final class HelpCenterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HelpScreens::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
