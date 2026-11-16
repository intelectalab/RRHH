<?php

namespace App\Shared\Documents;

/**
 * ESS-4 · Puerto del catálogo del legajo (DOC-1): otro módulo pide el identificador de un tipo documental del
 * sistema por su código (p. ej. work_certificate) y el módulo de documentos crea antes, si faltan, las carpetas
 * y tipos de partida, para que lo que se archive quede en su carpeta.
 */
interface DocumentTypes
{
    /** Identificador del tipo documental del sistema, o null si no existe ese código. */
    public function systemType(string $code): ?string;
}
