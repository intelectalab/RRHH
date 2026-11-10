<?php

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * SEG-8 · Catálogo central de permisos `modulo.recurso.accion` y permisos por campo.
 */
final class PermissionCatalogController
{
    public function __invoke(): JsonResponse
    {
        $rows = DB::table('permissions')
            ->orderBy('module_code')->orderBy('code')
            ->get(['code', 'module_code', 'resource', 'action', 'kind', 'is_sensitive', 'description']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'code' => $r->code,
            'module' => $r->module_code,
            'resource' => $r->resource,
            'action' => $r->action,
            'kind' => $r->kind,
            'sensitive' => (bool) $r->is_sensitive,
            'description' => $r->description,
        ])->all()]);
    }
}
