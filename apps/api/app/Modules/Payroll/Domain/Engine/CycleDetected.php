<?php

namespace App\Modules\Payroll\Domain\Engine;

/** NOM-4 · RN-NOM-06 · Las fórmulas de estos conceptos dependen unas de otras en círculo. */
final class CycleDetected extends \RuntimeException
{
    /** @param list<string> $concepts */
    public function __construct(public readonly array $concepts)
    {
        parent::__construct('cycle: '.implode(' → ', $concepts));
    }
}
