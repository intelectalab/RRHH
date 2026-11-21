<?php

namespace App\Modules\Ai;

use App\Modules\Ai\Console\PurgeAiInteractions;
use App\Modules\Ai\Domain\Ports\AiProvider;
use App\Modules\Ai\Infrastructure\FakeAiProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo IAR · Asistente de IA (MVP: asistente de ayuda documental). La decisión A-09 (proveedor real) sigue
 * abierta: el puerto AiProvider se resuelve siempre con el adaptador falso determinista hasta que se registre
 * en un ADR y se agregue su adaptador en Infrastructure/.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeAiProvider::class);
        $this->app->singleton(AiProvider::class, FakeAiProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeAiInteractions::class]);
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)->command('ai:purge-interactions')->dailyAt('03:20')->withoutOverlapping();
            });
        }
    }
}
