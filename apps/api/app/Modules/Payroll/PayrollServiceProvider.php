<?php

namespace App\Modules\Payroll;

use App\Modules\Payroll\Infrastructure\Subscribers\ProcessPayrollRun;
use App\Modules\Payroll\Infrastructure\Subscribers\RecalculateRetroactively;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo NOM · Motor de nómina (kit/docs/modulos/M-NOM.md). Módulo comercial payroll.
 * Núcleo genérico (Domain: fórmulas, dependencias y cálculo) + paquete de país (packages/country-xx/data/
 * motor_planilla.php) + régimen laboral por empresa y por trabajador. Se usa solo por servicios y por la API.
 * NOM-8: corridas en segundo plano; NOM-7: recálculo retroactivo al cambiar datos con fecha pasada.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        $outbox = $this->app->make(OutboxService::class);
        $outbox->subscribe($this->app->make(ProcessPayrollRun::class));
        $outbox->subscribe($this->app->make(RecalculateRetroactively::class));
    }
}
