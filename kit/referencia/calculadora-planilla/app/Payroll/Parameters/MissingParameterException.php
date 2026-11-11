<?php

declare(strict_types=1);

namespace App\Payroll\Parameters;

use App\Payroll\PayrollException;

/** Falta un parámetro o su valor en la fecha del periodo: la relación queda «con error» (RN-PAR-04). */
final class MissingParameterException extends PayrollException
{
    public static function notFound(string $code, ?string $regime, string $date): self
    {
        return new self(sprintf('No hay parámetro «%s»%s vigente al %s.', $code, $regime ? " para {$regime}" : '', $date));
    }

    public static function withoutValue(string $code, ?string $regime): self
    {
        return new self(sprintf('El parámetro «%s»%s no tiene valor verificado. Cárgalo desde la fuente oficial (docs/19).', $code, $regime ? " de {$regime}" : ''));
    }

    public static function pensionRates(string $fund, string $date): self
    {
        return new self(sprintf('No hay tasas de la administradora «%s» vigentes al %s.', $fund, $date));
    }
}
