<?php

namespace App\Shared\Payroll;

/**
 * CPT-4 · Contabilización de los conceptos de planilla: qué cuentas de debe y haber usa un concepto para una
 * empresa, centro de costo y categoría de trabajador a una fecha, y qué conceptos con efecto contable aún no
 * tienen cuentas (el cierre del periodo y el asiento, PER-8 y PRV, lo consultan antes de cerrar).
 */
interface ConceptAccounts
{
    /**
     * Regla más específica que aplica (empresa, centro de costo y categoría; las vacías valen para todos).
     *
     * @return array{debit_account_code: string, credit_account_code: string, rule_id: string}|null
     */
    public function resolve(string $conceptId, string $legalEntityId, ?string $costCenterId, ?string $workerCategory, string $date): ?array;

    /**
     * Conceptos activos con efecto contable sin una regla general de cuentas para la empresa a la fecha.
     *
     * @return list<array{id: string, code: string, name: string, kind: string}>
     */
    public function missing(string $legalEntityId, string $date): array;
}
