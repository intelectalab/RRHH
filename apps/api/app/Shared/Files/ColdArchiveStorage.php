<?php

namespace App\Shared\Files;

use Illuminate\Support\Facades\Storage;

/**
 * AUD-5 · Almacenamiento frío de archivos de registro (auditoría archivada). Disco aparte
 * (config audit.archive_disk), sin URL firmada: solo se lee desde la API con su permiso.
 */
final class ColdArchiveStorage
{
    public function disk(): string
    {
        return (string) config('audit.archive_disk');
    }

    /**
     * @param  resource  $stream
     */
    public function put(string $disk, string $key, $stream): void
    {
        Storage::disk($disk)->put($key, $stream);
    }

    public function exists(string $disk, string $key): bool
    {
        return Storage::disk($disk)->exists($key);
    }

    public function get(string $disk, string $key): ?string
    {
        return Storage::disk($disk)->get($key);
    }

    public function delete(string $disk, string $key): void
    {
        Storage::disk($disk)->delete($key);
    }
}
