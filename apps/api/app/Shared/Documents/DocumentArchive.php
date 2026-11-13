<?php

namespace App\Shared\Documents;

use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * Repositorio documental mínimo (tablas documents y document_versions) para los módulos que generan o
 * archivan documentos antes de que exista el módulo documental (M-DOC): cada documento vive en una
 * categoría, apunta al registro que lo origina (subject_type / subject_id) y guarda sus versiones con la
 * huella del archivo. Los binarios van al almacenamiento de archivos (FileService), nunca a la base.
 */
final class DocumentArchive
{
    public function __construct(private readonly FileService $files) {}

    /** Categoría de la organización por código (se crea la primera vez con el nombre indicado). */
    public function category(string $code, string $name, string $requiredPermission): string
    {
        $id = DB::table('document_categories')->where('code', $code)->whereNull('deleted_at')->value('id');
        if (is_string($id)) {
            return $id;
        }

        return (string) DB::table('document_categories')->insertGetId([
            'tenant_id' => TenancyScope::requireTenant(), 'code' => $code, 'name' => $name,
            'default_classification' => 'confidential', 'required_permission_code' => $requiredPermission,
        ]);
    }

    /**
     * Crea el documento con su primera versión.
     *
     * @param  array{category_id: string, title: string, employment_id?: string|null, person_id?: string|null, legal_entity_id?: string|null, subject_type: string, subject_id: string, source: string, status?: string, issued_on?: string|null}  $meta
     */
    public function create(array $meta, string $contents, string $fileName, string $mime, ?string $userId, ?string $notes = null): string
    {
        $tenant = TenancyScope::requireTenant();
        $documentId = (string) DB::table('documents')->insertGetId([
            'tenant_id' => $tenant, 'document_category_id' => $meta['category_id'], 'title' => $meta['title'],
            'owner_person_id' => $meta['person_id'] ?? null, 'employment_id' => $meta['employment_id'] ?? null,
            'legal_entity_id' => $meta['legal_entity_id'] ?? null, 'subject_type' => $meta['subject_type'],
            'subject_id' => $meta['subject_id'], 'classification' => 'confidential', 'status' => $meta['status'] ?? 'draft',
            'source' => $meta['source'], 'issued_on' => $meta['issued_on'] ?? null,
        ]);
        $this->addVersion($documentId, $contents, $fileName, $mime, $userId, $notes);

        return $documentId;
    }

    /** Agrega una versión y la deja como vigente. Devuelve el número de versión. */
    public function addVersion(string $documentId, string $contents, string $fileName, string $mime, ?string $userId, ?string $notes = null, ?string $status = null, ?string $source = null): int
    {
        $tenant = TenancyScope::requireTenant();
        $file = $this->files->upload($contents, $fileName, $mime, $tenant, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);
        $version = (int) DB::table('document_versions')->where('document_id', $documentId)->max('version') + 1;
        $versionId = (string) DB::table('document_versions')->insertGetId([
            'tenant_id' => $tenant, 'document_id' => $documentId, 'version' => $version, 'stored_file_id' => $file->id,
            'content_hash' => $file->sha256, 'uploaded_by_user_id' => $userId, 'notes' => $notes,
        ]);
        DB::table('documents')->where('id', $documentId)->update(array_filter([
            'current_version_id' => $versionId, 'status' => $status, 'source' => $source, 'updated_at' => now(),
        ], fn ($v) => $v !== null));

        return $version;
    }

    /** Número de la versión vigente (null si el documento no existe). */
    public function currentVersion(string $documentId): ?int
    {
        $v = DB::table('documents as d')->join('document_versions as v', 'v.id', '=', 'd.current_version_id')
            ->where('d.id', $documentId)->whereNull('d.deleted_at')->value('v.version');

        return $v === null ? null : (int) $v;
    }

    /**
     * Archivo de la versión vigente (la descarga queda registrada).
     *
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function download(string $documentId, ?string $userId): ?array
    {
        $fileId = DB::table('documents as d')->join('document_versions as v', 'v.id', '=', 'd.current_version_id')
            ->where('d.id', $documentId)->whereNull('d.deleted_at')->value('v.stored_file_id');

        return is_string($fileId) ? $this->files->contentsForDownload($fileId, null, $userId) : null;
    }
}
