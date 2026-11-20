<?php

namespace App\Modules\Payroll\Domain\Formula;

use Brick\Math\BigDecimal;

/**
 * NOM-3 · Lo único que una fórmula puede leer: el contexto de cálculo que arma el motor. El intérprete no
 * tiene otra salida (ni base de datos, ni reloj, ni archivos).
 */
interface FormulaScope
{
    /** Dato del contexto: número, texto (fechas AAAA-MM-DD, códigos) o verdadero/falso. */
    public function variable(string $name): BigDecimal|string|bool;

    /** Resultado (sin redondear) de un concepto ya calculado en el orden de dependencias. */
    public function concept(string $code): BigDecimal;

    /** Valor numérico del parámetro legal vigente a la fecha del periodo. */
    public function parameter(string $code): BigDecimal;

    /** Suma de los montos (incident) o de las cantidades (quantity) de las incidencias del concepto. */
    public function incident(string $code, string $field): BigDecimal;
}
