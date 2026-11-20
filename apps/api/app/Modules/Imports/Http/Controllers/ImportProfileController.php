<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Modules\Imports\Application\SourceProfiles;
use App\Shared\Exceptions\AyniException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IMP-7 · Perfiles de origen para importar exportaciones de otros sistemas. Listar es parte de ver
 * importaciones; agregar o corregir un perfil (versión nueva) es parte de mapear.
 */
final class ImportProfileController
{
    public function __construct(private readonly SourceProfiles $profiles) {}

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $entity = is_string($filter['entity'] ?? null) && $filter['entity'] !== '' ? $filter['entity'] : null;
        $code = is_string($filter['code'] ?? null) && $filter['code'] !== '' ? $filter['code'] : null;
        $data = [];
        foreach ($this->profiles->all($entity) as $profile) {
            if ($code !== null && $profile['code'] !== $code) {
                continue;
            }
            $data[] = $profile + ['versions' => $this->profiles->versions($profile['code'], $profile['entity'])];
        }

        return response()->json(['data' => $data, 'meta' => ['page' => 1, 'per_page' => max(1, count($data)), 'total' => count($data)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AyniException('unauthenticated', 401);
        }
        $userId = (string) $user->getAuthIdentifier();
        $locale = DB::table('users')->where('id', $userId)->value('locale');
        $profile = $this->profiles->save($request->all(), $userId, is_string($locale) && $locale !== '' ? $locale : app()->getLocale());

        return response()->json(['data' => $profile + ['versions' => $this->profiles->versions($profile['code'], $profile['entity'])]], 201);
    }
}
