<?php

declare(strict_types=1);

namespace App\Payroll\Settlement;

use DateTimeImmutable;

/**
 * Despido que da lugar a indemnización (DS 003-97-TR arts. 38 y 76). Si el cese no es un despido
 * arbitrario (renuncia, mutuo acuerdo, fin de contrato, falta grave), no se pasa este objeto.
 */
final readonly class Dismissal
{
    public const INDEFINITE = 'indefinite';

    public const FIXED_TERM = 'fixed_term';

    /**
     * @param  string  $contractType  indefinite (plazo indeterminado) o fixed_term (plazo fijo)
     * @param  ?DateTimeImmutable  $contractEnd  fin pactado del contrato a plazo fijo
     * @param  bool  $inProbation  el despido ocurre dentro del periodo de prueba: no hay indemnización
     */
    public function __construct(
        public string $contractType = self::INDEFINITE,
        public ?DateTimeImmutable $contractEnd = null,
        public bool $inProbation = false,
    ) {}
}
