<?php

namespace App\Modules\Payroll;

use Illuminate\Support\ServiceProvider;

/**
 * Módulo NOM · Motor de nómina (kit/docs/modulos/M-NOM.md). Módulo comercial payroll.
 * Núcleo genérico (Domain: fórmulas, dependencias y cálculo) + paquete de país (packages/country-xx/data/
 * motor_planilla.php) + régimen laboral por empresa y por trabajador. Se usa solo por servicios y por la API.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
