<?php

declare(strict_types=1);

namespace App\Payroll\Parameters;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Valor de un parámetro legal vigente en una fecha, con su origen para la trazabilidad (RN-NOM-04). */
final readonly class ParameterValue
{
    public function __construct(
        public string $code,
        public ?string $regimeCode,
        public ?BigDecimal $value,
        public string $unit,
        public bool $verified,
        public string $validFrom,
        public ?string $legalSource,
        public ?array $json = null,
    ) {}

    /** Valor estructurado: escalas, tablas por mes o listas de meses. */
    public function json(): array
    {
        if ($this->json === null) {
            throw MissingParameterException::withoutValue($this->code, $this->regimeCode);
        }

        return $this->json;
    }

    /** Los porcentajes se guardan como 13 = 13 %. Devuelve la fracción (0.13). */
    public function asRate(): BigDecimal
    {
        return $this->required()->dividedBy(100, 10, RoundingMode::HALF_UP);
    }

    public function required(): BigDecimal
    {
        if ($this->value === null) {
            throw MissingParameterException::withoutValue($this->code, $this->regimeCode);
        }

        return $this->value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'regime' => $this->regimeCode,
            'value' => $this->json ?? $this->value?->__toString(),
            'unit' => $this->unit,
            'verified' => $this->verified,
            'valid_from' => $this->validFrom,
            'legal_source' => $this->legalSource,
        ];
    }
}
