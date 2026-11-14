<?php

namespace App\Shared\Documents;

use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\Request;

/**
 * DOC-1 · Documentos que guarda otro módulo (p. ej. los sustentos y certificados de una ausencia) y que el legajo
 * muestra como referencias, sin copiar el archivo: el módulo dueño decide quién los ve con sus propios permisos
 * y entrega el archivo. Cada módulo registra su fuente en LinkedDocumentRegistry.
 */
interface LinkedDocumentSource
{
    /** Prefijo de la referencia (absence…): la referencia completa es «{key}:{id}». */
    public function key(): string;

    /**
     * Documentos de la relación laboral que el usuario puede ver. folder_code es la carpeta del legajo donde
     * aparecen; sensitive indica que su lectura queda en el registro de accesos.
     *
     * @return list<array{id: string, folder_code: string, title: string, subtitle: string|null, issued_on: string|null, file_name: string, mime_type: string, size_bytes: int, uploaded_at: string, sensitive: bool, link: string|null}>
     */
    public function forEmployment(string $employmentId, Request $request, EffectivePermissions $permissions): array;

    /**
     * Archivo del documento si el usuario puede verlo (la descarga queda registrada); null si no existe o no lo ve.
     *
     * @return array{name: string, mime: string, contents: string, sensitive: bool, person_id: string|null}|null
     */
    public function download(string $id, string $employmentId, Request $request, EffectivePermissions $permissions): ?array;
}
