<?php

namespace App\Modules\HelpCenter;

use App\Modules\HelpCenter\Application\HelpLibrary;
use App\Modules\HelpCenter\Console\MarkArticlesForReview;
use App\Shared\Help\HelpCorpus;
use App\Shared\Help\HelpScreens;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo AYU · Centro de ayuda de la organización (core). La ayuda del producto se redacta y publica en la
 * consola (Platform); aquí se lee la última versión publicada con las reglas de visibilidad RN-AYU-01, se
 * administran los artículos propios de la organización (AYU-4) y se derivan las consultas (AYU-6).
 */
final class HelpCenterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HelpScreens::class);
        $this->app->bind(HelpCorpus::class, HelpLibrary::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([MarkArticlesForReview::class]);
            $this->app->booted(function (): void {
                // AYU-4 · RN-AYU-04 · Artículos de la organización por revisar.
                $this->app->make(Schedule::class)->command('help:review-articles')->dailyAt('06:40')->withoutOverlapping();
            });
        }
    }
}
