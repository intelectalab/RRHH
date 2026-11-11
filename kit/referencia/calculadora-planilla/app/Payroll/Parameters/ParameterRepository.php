<?php

declare(strict_types=1);

namespace App\Payroll\Parameters;

use DateTimeInterface;

/**
 * Fuente de los parámetros legales. La calculadora solo conoce esta interfaz: en producción se lee de
 * legal_parameters y pension_fund_rates (con caché), y en pruebas de un arreglo en memoria.
 */
interface ParameterRepository
{
    /**
     * Devuelve el valor vigente en $date. Primero busca el valor propio del régimen y, si no existe,
     * el valor global del país. Lanza MissingParameterException si no hay ninguno.
     */
    public function get(string $code, ?string $regimeCode, DateTimeInterface $date): ParameterValue;

    /** Igual que get(), pero devuelve null si el parámetro no existe para el régimen ni para el país. */
    public function find(string $code, ?string $regimeCode, DateTimeInterface $date): ?ParameterValue;

    public function pensionFundRates(string $fundCode, DateTimeInterface $date): PensionFundRates;
}
