<?php

namespace App\Shared\Search;

use App\Shared\Audit\ActivityLogger;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /search?q= · Búsqueda global con permisos, alcance y organización.
 */
final class SearchController
{
    public function __invoke(Request $request, SearchRegistry $registry, PermissionResolver $resolver, ActivityLogger $activity): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $user = $request->user();
        if (mb_strlen($term) < 2 || ! $user instanceof Authenticatable) {
            return response()->json(['data' => []]);
        }

        $permissions = $resolver->for((string) $user->getAuthIdentifier());

        $results = $registry->search(mb_substr($term, 0, 80), $request, $permissions);
        // AUD-3: la búsqueda queda en la actividad del usuario, sin el texto buscado.
        $activity->record('search.performed', null, null, ['results' => count($results)]);

        return response()->json(['data' => $results]);
    }
}
