<?php

namespace App\Modules\Documents\Infrastructure\Catalog;

use App\Modules\Documents\Application\DocumentCatalog;
use App\Shared\Documents\DocumentTypes;
use Illuminate\Support\Facades\DB;

/** ESS-4 · Tipos documentales del sistema para los módulos que archivan en el legajo (constancias). */
final class DocumentTypesAdapter implements DocumentTypes
{
    public function __construct(private readonly DocumentCatalog $catalog) {}

    public function systemType(string $code): ?string
    {
        $this->catalog->ensureDefaults();
        $id = DB::table('document_categories')->where('code', $code)->where('is_system', true)->whereNull('deleted_at')->value('id');

        return is_string($id) ? $id : null;
    }
}
