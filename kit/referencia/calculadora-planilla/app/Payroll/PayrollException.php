<?php

declare(strict_types=1);

namespace App\Payroll;

use RuntimeException;

/** Error de datos que deja a un colaborador «con error» sin detener el resto de la planilla (RN-NOM-10). */
class PayrollException extends RuntimeException
{
    public static function notInPeriod(string $name, string $period): self
    {
        return new self("{$name} no tiene días laborados en {$period}: revisa la fecha de ingreso y de cese.");
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
