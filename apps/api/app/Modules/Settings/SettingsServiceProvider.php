<?php

namespace App\Modules\Settings;

use App\Modules\Settings\Infrastructure\Trash\CustomFieldTrashSource;
use App\Shared\Trash\TrashRegistry;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $this->app->make(TrashRegistry::class)->register($this->app->make(CustomFieldTrashSource::class));
    }
}
