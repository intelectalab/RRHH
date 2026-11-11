<?php

namespace App\Shared\Imports;

/**
 * Datos de la importación en curso que el importador puede necesitar.
 */
final class ImportContext
{
    /**
     * Contadores que el importador lleva entre filas de una misma pasada (p. ej. cupo del plan).
     *
     * @var array<string, int>
     */
    public array $counters = [];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $jobId,
        public readonly ?string $userId,
        public readonly string $locale,
    ) {}
}
