<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Application\Console\ConsoleRoles;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permiso `platform.*` según el rol interno de la consola (regla 10: lo valida el servidor).
 * Uso en rutas: ->middleware('platform.permission:platform.metrics.view').
 */
final class RequirePlatformPermission
{
    public const ALIAS = 'platform.permission';

    public function __construct(private readonly ConsoleRoles $roles) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user('platform');
        if (! $user instanceof PlatformUser) {
            return ProblemDetailsResponse::make(401, 'unauthenticated');
        }
        if (! $this->roles->can($user, $permission)) {
            return ProblemDetailsResponse::make(403, 'forbidden', null, null, [], ['permission' => $permission]);
        }

        return $next($request);
    }
}
