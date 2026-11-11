<?php

namespace App\Modules\Reports\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Quién exporta: identificador, nombre (para la marca de agua) e idioma de los encabezados.
 */
final readonly class ExportActor
{
    public function __construct(public string $id, public string $name, public string $locale) {}

    public static function from(Authenticatable $user): self
    {
        $id = (string) $user->getAuthIdentifier();
        $row = DB::table('users')->where('id', $id)->first(['display_name', 'locale']);

        return new self($id, (string) ($row->display_name ?? ''), (string) (($row->locale ?? '') ?: 'es'));
    }
}
