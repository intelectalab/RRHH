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
            ->join('modules', 'modules.code', '=', 'permissions.module_code')
            ->orderBy('modules.phase')->orderBy('modules.name')->orderBy('permissions.code')
            ->get(['permissions.code', 'permissions.module_code', 'modules.name as module_name', 'permissions.resource', 'permissions.action', 'permissions.kind', 'permissions.is_sensitive', 'permissions.description']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'code' => $r->code,
            'module' => $r->module_code,
            'module_name' => $r->module_name,
            'resource' => $r->resource,
            'action' => $r->action,
            'kind' => $r->kind,
            'sensitive' => (bool) $r->is_sensitive,
            'description' => $r->description,
        ])->all()]);
    }
}
