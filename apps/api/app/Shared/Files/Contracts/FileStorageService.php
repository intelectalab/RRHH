<?php

namespace App\Shared\Files\Contracts;

use App\Shared\Files\Models\StoredFile;
use Illuminate\Http\UploadedFile;

interface FileStorageService
{
    /**
     * Sube y almacena un archivo validando tipo y tamaño, y registra sus metadatos.
     */
    public function upload(
        UploadedFile|string $fileContent,
        string $originalName,
        string $mimeType,
        ?string $tenantId = null,
        ?string $userId = null,
        string $classification = StoredFile::CLASSIFICATION_INTERNAL
    ): StoredFile;

    /**
     * Genera una URL firmada de corta duración para descarga y registra el evento.
     */
    public function getSignedUrl(
        string $fileId,
        ?string $tenantId = null,
        ?string $userId = null,
        ?int $ttlSeconds = null
    ): string;

    /**
     * Elimina lógicamente un archivo (soft delete) y de almacenamiento.
     */
    public function delete(string $fileId, ?string $tenantId = null): void;
}

