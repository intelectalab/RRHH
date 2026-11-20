<?php

namespace App\Modules\Payroll\Domain\Engine;

/**
 * NOM-1 · Concepto tal como lo ejecuta el núcleo: la versión vigente a la fecha del periodo (RN-NOM-01).
 * Sin fórmula, el concepto toma el monto de sus incidencias del periodo (concepto de ingreso manual).
 */
final class ConceptDefinition
{
    /**
     * @param  list<string>|null  $regimes  regímenes a los que aplica (null = todos, RN-NOM-09)
     * @param  list<string>|null  $periodKinds  tipos de proceso a los que aplica (null = todos)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $formula,
        public readonly string $rounding = 'half_up',
        public readonly int $sortOrder = 0,
        public readonly ?array $regimes = null,
        public readonly ?array $periodKinds = null,
        public readonly ?string $conceptId = null,
        public readonly ?string $versionId = null,
        public readonly ?string $validFrom = null,
    ) {}

    public function appliesTo(?string $regime, string $periodKind): bool
    {
        return $this->appliesToRegime($regime) && $this->appliesToKind($periodKind);
    }

    public function appliesToRegime(?string $regime): bool
    {
        return $this->regimes === null || $this->regimes === [] || ($regime !== null && in_array($regime, $this->regimes, true));
    }

    public function appliesToKind(string $periodKind): bool
    {
        return $this->periodKinds === null || $this->periodKinds === [] || in_array($periodKind, $this->periodKinds, true);
    }
}
