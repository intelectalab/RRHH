<?php

namespace App\Shared\CustomFields;

/**
 * CFG-5 · Lo implementa un importador o una fuente de exportación cuyos registros admiten campos
 * personalizados: el motor común agrega las columnas custom.clave, las valida con su tipo y las
 * guarda o filtra, sin que el módulo programe nada más.
 */
interface HasCustomFields
{
    /** Entidad de config/custom_fields.php (employment, legal_entity, task…). */
    public function customFieldEntity(): string;
}
