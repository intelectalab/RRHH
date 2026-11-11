<?php

namespace App\Shared\Imports;

/**
 * Columna que admite un importador. La etiqueta se traduce en imports.columns.{entity}.{key}.
 */
final class ImportColumn
{
    /**
     * @param  array<int, string>  $aliases  otros nombres de cabecera con que suele venir (para proponer el mapeo)
     * @param  string|null  $fieldPermission  permiso por campo para importar un dato sensible (RN-IMP-04)
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $required = false,
        public readonly string $example = '',
        public readonly array $aliases = [],
        public readonly ?string $fieldPermission = null,
    ) {}
}
