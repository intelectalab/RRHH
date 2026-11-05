<?php

namespace App\Shared\Files;

use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Files\Contracts\FileStorageService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileService implements FileStorageService
{
    public function __construct(
        private readonly ?OutboxWriter $outbox = null
    ) {}

    /**
     * Sube un archivo validando tipo MIME y tamaño máximo según configuración.
     */
    public function upload(
        UploadedFile|string $fileContent,
        string $originalName,
        string $mimeType,
        ?string $tenantId = null,
        ?string $userId = null,
        string $classification = StoredFile::CLASSIFICATION_INTERNAL
    ): StoredFile {
        $resolvedTenantId = $tenantId ?? TenancyScope::current();
        if (empty($resolvedTenantId)) {
            throw new BusinessRuleViolatedException('TEN-01', 'Se requiere contexto de organización (tenant_id) para almacenar archivos.');
        }

        // Obtener contenido binario y tamaño
        if ($fileContent instanceof UploadedFile) {
            $contents = $fileContent->getContent();
            $mimeType = $fileContent->getMimeType() ?? $mimeType;
            $originalName = $fileContent->getClientOriginalName();
            $sizeBytes = $fileContent->getSize() > 0 ? $fileContent->getSize() : strlen($contents);
        } else {
            $contents = $fileContent;
            $sizeBytes = strlen($contents);
        }

        // Validación de tamaño máximo (RN-PLT-10)
        /** @var int $maxSize */
        $maxSize = config('files.max_size_bytes', 25 * 1024 * 1024);
        if ($sizeBytes > $maxSize) {
            throw new BusinessRuleViolatedException(
                'RN-PLT-10',
                "El archivo supera el tamaño máximo permitido de {$maxSize} bytes."
            );
        }

        // Validación de tipo MIME permitido (RN-PLT-10)
        /** @var array<int, string> $allowedMimes */
        $allowedMimes = config('files.allowed_mimes', []);
        if (! in_array($mimeType, $allowedMimes, true)) {
            throw new BusinessRuleViolatedException(
                'RN-PLT-10',
                "El tipo de archivo '{$mimeType}' no está permitido."
            );
        }

        $fileId = (string) Str::uuid();
        $sha256 = hash('sha256', $contents);
        $cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $originalName) ?? 'file';

        // Ruta con separación por organización: tenants/{tenant_id}/{year}/{month}/{file_id}_{nombre}
        $year = date('Y');
        $month = date('m');
        $storageKey = "tenants/{$resolvedTenantId}/{$year}/{$month}/{$fileId}_{$cleanName}";

        // Almacenar en disco configurado (S3/MinIO)
        /** @var string $diskName */
        $diskName = config('files.disk', 's3');
        Storage::disk($diskName)->put($storageKey, $contents);

        /** @var string $scanStatus */
        $scanStatus = config('files.scan_status_default', StoredFile::SCAN_CLEAN);

        // Registrar metadatos en base de datos
        $storedFile = StoredFile::create([
            'id' => $fileId,
            'tenant_id' => $resolvedTenantId,
            'storage_key' => $storageKey,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'classification' => $classification,
            'uploaded_by_user_id' => $userId,
            'scan_status' => $scanStatus,
        ]);

        // Registrar evento en outbox
        if ($this->outbox !== null) {
            $this->outbox->record(
                eventType: DomainEventTypes::DOCUMENT_UPLOADED,
                aggregateType: 'stored_file',
                aggregateId: $fileId,
                payload: [
                    'stored_file_id' => $fileId,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                ],
                tenantId: $resolvedTenantId,
            );
        }

        return $storedFile;
    }

    /**
     * Genera URL firmada de corta duración para descarga y registra el evento en auditoría (RN-PLT-07).
     */
    public function getSignedUrl(
        string $fileId,
        ?string $tenantId = null,
        ?string $userId = null,
        ?int $ttlSeconds = null
    ): string {
        $resolvedTenantId = $tenantId ?? TenancyScope::current();

        $query = StoredFile::where('id', $fileId);
        if (! empty($resolvedTenantId)) {
            $query->where('tenant_id', $resolvedTenantId);
        }

        /** @var StoredFile|null $file */
        $file = $query->first();

        if ($file === null) {
            throw (new ModelNotFoundException)->setModel(StoredFile::class, [$fileId]);
        }

        $ttl = $ttlSeconds ?? (int) config('files.signed_url_ttl_seconds', 300);
        $expiresAt = now()->addSeconds($ttl);

        /** @var string $diskName */
        $diskName = config('files.disk', 's3');
        $disk = Storage::disk($diskName);

        // Genera URL temporal firmada compatible con S3/MinIO
        try {
            $signedUrl = $disk->temporaryUrl($file->storage_key, $expiresAt);
        } catch (\Throwable) {
            // En entornos o discos que no soportan temporaryUrl directamente (p. ej. local sin adapter),
            // generamos una URL firmada alternativa basada en la ruta configurada
            $signedUrl = url("/api/v1/files/{$file->id}/download?expires={$expiresAt->timestamp}&signature=".hash_hmac('sha256', "{$file->id}:{$expiresAt->timestamp}", (string) config('app.key')));
        }

        // Registrar la descarga en activity_events (RN-PLT-07: cada descarga queda registrada)
        $this->logDownload($file, $userId);

        return $signedUrl;
    }

    /**
     * Elimina lógicamente el archivo de la base de datos y físicamente del almacenamiento.
     */
    public function delete(string $fileId, ?string $tenantId = null): void
    {
        $resolvedTenantId = $tenantId ?? TenancyScope::current();

        $query = StoredFile::where('id', $fileId);
        if (! empty($resolvedTenantId)) {
            $query->where('tenant_id', $resolvedTenantId);
        }

        /** @var StoredFile|null $file */
        $file = $query->first();

        if ($file === null) {
            throw (new ModelNotFoundException)->setModel(StoredFile::class, [$fileId]);
        }

        /** @var string $diskName */
        $diskName = config('files.disk', 's3');
        Storage::disk($diskName)->delete($file->storage_key);

        $file->delete();
    }

    /**
     * Registra el evento de descarga en activity_events y en data_access_logs si es sensible.
     */
    private function logDownload(StoredFile $file, ?string $userId = null): void
    {
        DB::table('activity_events')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $file->tenant_id,
            'user_id' => $userId,
            'activity' => 'file.downloaded',
            'subject_type' => 'stored_file',
            'subject_id' => $file->id,
            'metadata' => json_encode([
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'classification' => $file->classification,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        // Si el archivo tiene clasificación confidencial o restringida, registrar en data_access_logs
        if (in_array($file->classification, [StoredFile::CLASSIFICATION_CONFIDENTIAL, StoredFile::CLASSIFICATION_RESTRICTED], true)) {
            DB::table('data_access_logs')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $file->tenant_id,
                'user_id' => $userId,
                'access_kind' => 'download',
                'data_category' => 'other',
                'entity_type' => 'stored_file',
                'entity_id' => $file->id,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }
    }
}
