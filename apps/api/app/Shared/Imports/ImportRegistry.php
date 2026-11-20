<?php

namespace App\Shared\Imports;

/**
 * IMP-1 · Registro de importadores. Cada módulo registra los suyos en su proveedor.
 */
final class ImportRegistry
{
    /** @var array<string, Importer> */
    private array $importers = [];

    public function register(Importer $importer): void
    {
        // CFG-5: los importadores con campos personalizados los manejan con el tratamiento común.
        $this->importers[$importer->entity()] = CustomFieldsImporter::wrap($importer);
    }

    public function get(string $entity): ?Importer
    {
        return $this->importers[$entity] ?? null;
    }

    /** @return array<string, Importer> */
    public function all(): array
    {
        return $this->importers;
    }
}
