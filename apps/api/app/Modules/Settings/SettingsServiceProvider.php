<?php

namespace App\Modules\Settings;

use App\Modules\Settings\Infrastructure\Calendar\HolidayCalendarSource;
use App\Modules\Settings\Infrastructure\Console\ImportCatalogsCommand;
use App\Modules\Settings\Infrastructure\Setup\HolidaysPackComponent;
use App\Modules\Settings\Infrastructure\Trash\CustomFieldTrashSource;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Setup\CountryPackRegistry;
use App\Shared\Trash\TrashRegistry;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // IMP-4 · Parte del paquete de país que siembra este módulo.
        $this->app->make(CountryPackRegistry::class)->register($this->app->make(HolidaysPackComponent::class));
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(TrashRegistry::class)->register($this->app->make(CustomFieldTrashSource::class));
        $this->app->make(CalendarRegistry::class)->register($this->app->make(HolidayCalendarSource::class));
        if ($this->app->runningInConsole()) {
            $this->commands([ImportCatalogsCommand::class]);
        }
    }
}
