<?php

namespace App\Shared\Imports;

use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\PermissionResolver;

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

    /**
     * IMP-2 · Lo que el importador recuerda entre filas de una misma pasada: códigos o documentos que
     * aparecen antes en el archivo (una unidad cuyo padre viene en una fila anterior, el último tramo
     * de sueldo de una persona).
     *
     * @var array<string, mixed>
     */
    public array $memo = [];

    private ?EffectivePermissions $permissions = null;

    private ?ImportLookups $lookups = null;

    /** Búsqueda de códigos del archivo; vive lo que dura esta pasada (no arrastra datos de otra importación). */
    public function lookups(): ImportLookups
    {
        return $this->lookups ??= new ImportLookups;
    }

    public function __construct(
        public readonly string $tenantId,
        public readonly string $jobId,
        public readonly ?string $userId,
        public readonly string $locale,
    ) {}

    /** Permiso de quien creó la importación (p. ej. el permiso por campo del sueldo o aprobar posiciones). */
    public function can(string $permission): bool
    {
        if ($this->userId === null) {
            return false;
        }
        $this->permissions ??= app(PermissionResolver::class)->for($this->userId);

        return $this->permissions->has($permission);
    }
}
