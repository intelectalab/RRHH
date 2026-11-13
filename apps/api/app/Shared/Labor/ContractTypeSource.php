<?php

namespace App\Shared\Labor;

/**
 * Contrato entre módulos (CON → COL): el ingreso de un colaborador (COL-2) necesita que los tipos de
 * contrato del paquete de país existan en la organización antes de ofrecerlos y validarlos. Contratos
 * (CON-1) implementa la precarga; Core HR solo la pide, sin importar sus clases.
 */
interface ContractTypeSource
{
    /** Crea en la organización actual los tipos de contrato del paquete de país que falten. */
    public function ensureCountryTypes(): void;
}
