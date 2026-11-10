<?php

namespace App\Shared\Trash;

/**
 * CFG-9 · Contrato para que cada módulo publique sus registros eliminados (borrado lógico) en la
 * papelera y sepa restaurarlos con sus relaciones intactas.
 */
interface TrashSource
{
    /** Tipo de registro (role, task…). */
    public function type(): string;

    /** Permiso del recurso que además exige ver o restaurar (el de la papelera solo no basta). */
    public function permission(): string;

    /**
     * @return array<int, array{id: string, title: string, subtitle: string|null, deleted_at: string}>
     */
    public function items(?string $search, int $limit): array;

    public function owns(string $id): bool;

    /** Restaura; lanza AyniException si no se puede (p. ej. 409 por un duplicado activo). */
    public function restore(string $id): void;
}
