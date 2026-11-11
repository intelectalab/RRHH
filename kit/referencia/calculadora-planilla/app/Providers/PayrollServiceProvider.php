<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payroll\Parameters\EloquentParameterRepository;
use App\Payroll\Parameters\ParameterRepository;
use App\Payroll\Settlement\SettlementService;
use App\Services\PayrollService;
use Illuminate\Support\ServiceProvider;

final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: un repositorio (y su memoria de parámetros) por solicitud o por trabajo en cola.
        $this->app->scoped(ParameterRepository::class, fn () => new EloquentParameterRepository((string) config('payroll.country')));

        $this->app->scoped(PayrollService::class, fn ($app) => new PayrollService(
            $app->make(ParameterRepository::class),
            array_map(fn (string $class) => $app->make($class), (array) config('payroll.steps')),
            (int) config('payroll.scale'),
        ));

        $this->app->scoped(SettlementService::class, fn ($app) => new SettlementService(
            $app->make(ParameterRepository::class),
            (int) config('payroll.scale'),
        ));
    }
}
