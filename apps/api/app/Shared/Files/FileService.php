<?php

namespace App\Shared\Files;

use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Files\Contracts\FileStorageService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Usage\UsageLimits;
use App\Shared\Usage\UsageMetrics;
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

        // SAS-6 · RN-SAS-03: con el almacenamiento del plan lleno, la subida se rechaza; la lectura sigue.
        $usage = app(UsageLimits::class);
        $this->inTenant($resolvedTenantId, fn () => $usage->assertCanStore($sizeBytes));

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

        $this->inTenant($resolvedTenantId, fn () => $usage->record(UsageMetrics::STORAGE_MB));

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
        $file = $this->findOwnedFile($fileId, $tenantId);

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
     * SEG-12 · Contenido del archivo para entregarlo a través de la API (cuando el recurso dueño
     * ya validó el permiso). Registra la descarga igual que la URL firmada (RN-PLT-07).
     *
     * @return array{name: string, mime: string, contents: string}
     */
    public function contentsForDownload(string $fileId, ?string $tenantId = null, ?string $userId = null): array
    {
        $file = $this->findOwnedFile($fileId, $tenantId);
        /** @var string $diskName */
        $diskName = config('files.disk', 's3');
        $contents = Storage::disk($diskName)->get($file->storage_key);
        if ($contents === null) {
            throw new BusinessRuleViolatedException('RN-PLT-07', 'El archivo no está disponible en el almacenamiento.');
        }
        $this->logDownload($file, $userId);

        return ['name' => (string) $file->original_name, 'mime' => (string) $file->mime_type, 'contents' => $contents];
    }

    /**
     * CFG-2 · Contenido de un archivo público de la organización (logo de una empresa) para mostrarlo
     * en la interfaz. Solo para la clasificación pública: no deja registro de descarga.
     *
     * @return array{mime: string, contents: string}|null
     */
    public function publicContents(string $fileId, ?string $tenantId = null): ?array
    {
        $file = $this->findOwnedFile($fileId, $tenantId);
        if ($file->classification !== StoredFile::CLASSIFICATION_PUBLIC) {
            return null;
        }
        /** @var string $diskName */
        $diskName = config('files.disk', 's3');
        $contents = Storage::disk($diskName)->get($file->storage_key);

        return $contents === null ? null : ['mime' => (string) $file->mime_type, 'contents' => $contents];
    }

    /**
     * Elimina lógicamente el archivo de la base de datos y físicamente del almacenamiento.
     */
    public function delete(string $fileId, ?string $tenantId = null): void
    {
        $file = $this->findOwnedFile($fileId, $tenantId);

        /** @var string $diskName */
        $diskName = config('files.disk', 's3');
        Storage::disk($diskName)->delete($file->storage_key);

        $file->delete();
        // SAS-6 · El consumo de almacenamiento baja con cada borrado.
        $this->inTenant((string) $file->tenant_id, fn () => app(UsageLimits::class)->record(UsageMetrics::STORAGE_MB));
    }

    /**
     * Busca el archivo dentro de la organización activa (TEN-6). Sin organización no
     * hay búsqueda; un archivo de otra organización o con una ruta fuera de
     * tenants/{tenant_id}/ se trata como inexistente.
     */
    private function findOwnedFile(string $fileId, ?string $tenantId): StoredFile
    {
        $resolvedTenantId = $tenantId ?? TenancyScope::requireTenant();

        /** @var StoredFile|null $file */
        $file = StoredFile::where('id', $fileId)->where('tenant_id', $resolvedTenantId)->first();

        if ($file === null || ! str_starts_with((string) $file->storage_key, "tenants/{$resolvedTenantId}/")) {
            throw (new ModelNotFoundException)->setModel(StoredFile::class, [$fileId]);
        }

        return $file;
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

    /**
     * @param  callable(): mixed  $callback
     */
    private function inTenant(string $tenantId, callable $callback): void
    {
        if (TenancyScope::current() === $tenantId) {
            $callback();

            return;
        }
        TenancyScope::runAs($tenantId, $callback);
    }
}
