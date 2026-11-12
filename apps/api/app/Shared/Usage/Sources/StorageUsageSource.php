<?php

namespace App\Shared\Usage\Sources;

use App\Shared\Usage\UsageAmount;
use App\Shared\Usage\UsageMetrics;
use App\Shared\Usage\UsageSource;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · RN-SAS-03 · Almacenamiento usado: suma de los archivos vigentes de la organización (los que
 * están en la papelera de archivos ya no ocupan, porque el binario se borra al eliminarlos).
 */
final class StorageUsageSource implements UsageSource
{
    public function metric(): string
    {
        return UsageMetrics::STORAGE_MB;
    }

    public function measure(): int
    {
        return UsageAmount::bytesToMbCents($this->bytes());
    }

    public function bytes(): int
    {
        return (int) DB::table('stored_files')->whereNull('deleted_at')->sum('size_bytes');
    }
}
