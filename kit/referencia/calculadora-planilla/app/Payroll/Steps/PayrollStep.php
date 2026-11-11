<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;

/**
 * Un paso de la calculadora. Para añadir un bono, un descuento o un aporte nuevo se crea una clase que
 * implemente esta interfaz y se registra en config/payroll.php. Ningún paso escribe tasas en el código:
 * las pide a CalculationState::param().
 */
interface PayrollStep
{
    public function apply(CalculationState $state): void;
}
