<?php

namespace App\Shared\Exports;

use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\Request;

/**
 * SEG-12 · Contrato para que cada módulo aporte sus listados exportables. Cada fuente produce las
 * filas con el mismo alcance y los mismos permisos por campo que su pantalla: lo que el usuario
 * no puede ver en la API tampoco sale en el archivo.
 */
interface ExportSource
{
    /** Clave del listado (users, legal_entities…). */
    public function resource(): string;

    /** Permiso para ver el listado; exportar exige además reports.exports.create. */
    public function permission(): string;

    /** @return array<int, string> filtros admitidos */
    public function filters(): array;

    /**
     * Columnas (claves estables, traducidas en exports.columns.*), filas y campos sensibles
     * incluidos (quedan en el registro de accesos con el canal «export»).
     *
     * @param  array<string, string>  $filters
     * @return array{columns: array<int, string>, rows: array<int, array<int, string>>, sensitive: array<int, string>, truncated: bool}
     */
    public function build(array $filters, Request $request, EffectivePermissions $permissions, int $limit): array;
}
