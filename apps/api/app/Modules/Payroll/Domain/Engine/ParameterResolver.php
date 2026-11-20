<?php

namespace App\Modules\Payroll\Domain\Engine;

/**
 * NOM-1 · Dónde lee el núcleo los parámetros: la implementación decide país, régimen, valor propio de la
 * organización y fecha. El núcleo solo guarda en la traza lo que recibió (RN-NOM-04).
 */
interface ParameterResolver
{
    /**
     * @return array{code: string, value: string|null, unit: string, valid_from: string|null, valid_to: string|null, verified: bool, pending_validation: bool, legal_source: string|null, origin: string}|null
     */
    public function resolve(string $code): ?array;
}
