<?php

namespace App\Shared\Imports;

use RuntimeException;

/**
 * El archivo no se puede leer como tabla. El código se traduce en imports.file.{code}.
 */
final class SpreadsheetException extends RuntimeException
{
    /**
     * @param  array<string, int|string>  $params
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
