<?php

namespace App\Shared\Search;

use App\Shared\Permissions\PermissionResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /search?q= · Búsqueda global con permisos, alcance y organización.
 */
final class SearchController
{
    public function __invoke(Request $request, SearchRegistry $registry, PermissionResolver $resolver): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $user = $request->user();
        if (mb_strlen($term) < 2 || ! $user instanceof Authenticatable) {
            return response()->json(['data' => []]);
        }

        $permissions = $resolver->for((string) $user->getAuthIdentifier());

        return response()->json(['data' => $registry->search(mb_substr($term, 0, 80), $request, $permissions)]);
    }
}
