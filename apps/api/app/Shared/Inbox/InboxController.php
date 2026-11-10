<?php

namespace App\Shared\Inbox;

use App\Shared\Permissions\PermissionResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /inbox · Pendientes del usuario actual (aprobaciones, tareas y lo que registren los módulos).
 */
final class InboxController
{
    public function __invoke(Request $request, InboxRegistry $registry, PermissionResolver $resolver): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);
        $userId = (string) $user->getAuthIdentifier();
        $limit = min(100, max(1, (int) $request->query('limit', '50')));

        return response()->json(['data' => $registry->collect($userId, $resolver->for($userId), $limit)]);
    }
}
