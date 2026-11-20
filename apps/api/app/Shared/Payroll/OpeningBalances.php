<?php

namespace App\Shared\Payroll;

/**
 * PER-12 · Puerto de lectura de los acumulados del ejercicio traídos del sistema anterior. Lo usan la proyección
 * de quinta (ingresos y retenciones previas) y los beneficios del semestre en curso (gratificación y CTS, BSO-1/2):
 * lo importado se suma a lo calculado en el producto, nunca se presenta como boleta.
 */
interface OpeningBalances
{
    /**
     * Ingresos afectos y retenciones de quinta del ejercicio antes de empezar en el producto.
     *
     * @return array{cutoff: ?string, income: string, withheld: string, withheld_by_month: array<int, string>}
     */
    public function fifthCategory(string $employmentId, int $fiscalYear): array;

    /**
     * Total importado de un tipo (gratification_base, cts_base, cts_deposit, pension_contribution, health_contribution)
     * en un ejercicio y, si se indica, en un semestre.
     *
     * @return array{cutoff: ?string, amount: string}
     */
    public function total(string $employmentId, int $fiscalYear, string $kind, ?int $semester = null): array;
}
