<?php

namespace App\Shared\Payroll;

/**
 * NOM-1 · RN-NOM-12 · Lo que un paquete de país aporta al motor de nómina: las estrategias de sus regímenes
 * laborales y la regla de redondeo. El núcleo no tiene condicionales por país ni por régimen; agregar un
 * régimen es agregar su estrategia al paquete (packages/country-xx/data/motor_planilla.php).
 */
interface PayrollCountryPack
{
    public function country(): string;

    public function version(): string;

    /** Decimales del redondeo final de cada concepto (RN-NOM-08). */
    public function decimals(): int;

    /**
     * Estrategia del régimen; null si el paquete aún no lo soporta.
     *
     * @return array{code: string, kind: string, proration_parameter: string, full_month_equals_base: bool, requires_accreditation: bool, pending_validation: bool}|null
     */
    public function regime(string $code): ?array;

    /**
     * @return list<array{code: string, kind: string, proration_parameter: string, full_month_equals_base: bool, requires_accreditation: bool, pending_validation: bool}>
     */
    public function regimes(): array;
}
