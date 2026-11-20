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
     * NOM-9 · Estrategia del régimen; null si el paquete aún no lo soporta. `strategy` agrupa regímenes que
     * calculan igual con parámetros distintos (micro y pequeña usan la general); `processes` son los tipos
     * de periodo que el régimen genera (un régimen sin gratificación no tiene ese proceso).
     *
     * @return array{code: string, kind: string, strategy: string, pay_unit: string, proration_parameter: string, full_month_equals_base: bool, requires_accreditation: bool, processes: list<string>, gratifications: bool, pending_validation: bool}|null
     */
    public function regime(string $code): ?array;

    /**
     * @return list<array{code: string, kind: string, strategy: string, pay_unit: string, proration_parameter: string, full_month_equals_base: bool, requires_accreditation: bool, processes: list<string>, gratifications: bool, pending_validation: bool}>
     */
    public function regimes(): array;

    /**
     * NOM-10 · Régimen que se propone cuando la empresa no tiene acreditación vigente o la relación es
     * anterior a ella (RN-PE-MYP-02/03); null si el paquete no lo define.
     */
    public function defaultRegime(): ?string;
}
