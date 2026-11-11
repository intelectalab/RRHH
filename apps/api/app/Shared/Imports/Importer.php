<?php

namespace App\Shared\Imports;

/**
 * IMP-1 · Contrato que cada módulo implementa para que sus datos se puedan importar.
 *
 * El motor (app/Modules/Imports) lee el archivo, propone el mapeo, valida, muestra la vista previa,
 * importa por lotes y deshace. El importador solo conoce sus reglas: valida una fila sin escribir
 * nada, crea el registro con las mismas reglas que el alta manual (RN-IMP-06), dice si un
 * registro importado ya está en uso (RN-IMP-07) y lo deshace.
 */
interface Importer
{
    /** Tipo de datos (import_jobs.entity): legal_entities, employees… */
    public function entity(): string;

    /** Permiso del módulo dueño para crear estos registros; se exige además de imports.imports.*. */
    public function permission(): string;

    /** @return array<int, ImportColumn> */
    public function columns(): array;

    /**
     * Clave natural para detectar filas repetidas dentro del mismo archivo (null si no aplica).
     *
     * @param  array<string, string>  $data
     */
    public function naturalKey(array $data): ?string;

    /**
     * Valida una fila sin escribir: formatos, catálogos, duplicados contra lo existente y reglas del
     * módulo. Devuelve los datos interpretados y los problemas encontrados.
     *
     * @param  array<string, string>  $data  valores por clave de columna (ya recortados)
     */
    public function validate(array $data, ImportContext $context): RowCheck;

    /**
     * Crea (o actualiza) el registro. Corre dentro de la transacción del lote: si lanza una
     * excepción, el lote completo se deshace.
     *
     * @param  array<string, mixed>  $parsed  datos interpretados por validate()
     * @return array{entity_id: string, previous: array<string, mixed>|null}
     */
    public function import(array $parsed, ImportContext $context): array;

    /**
     * Motivo por el que un registro importado ya no puede deshacerse (lo usa otro proceso), o null.
     */
    public function blockingReason(string $entityId, ImportContext $context): ?string;

    /**
     * Deshace la fila: elimina el registro creado (borrado lógico) o restaura los valores anteriores.
     *
     * @param  array<string, mixed>|null  $previous
     */
    public function revert(string $entityId, ?array $previous, ImportContext $context): void;
}
