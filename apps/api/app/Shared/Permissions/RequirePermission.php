<?php

namespace App\Shared\Permissions;

use App\Shared\Exceptions\ModuleNotEnabledException;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprobación de autorización en cada petición (M-SEG §6):
 * autenticado → módulo contratado → permiso de acción. El alcance concedido queda
 * en la petición (`permission_scope`) para que la consulta lo aplique.
 *
 * Uso en rutas: ->middleware('permission:identity.roles.view')
 */
final class RequirePermission
{
    public const ALIAS = 'permission';

    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly ModuleGate $modules,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException;
        }

        $module = $this->modules->moduleOf($permission);
        if ($module === null) {
            // Un permiso que no está en el catálogo es un error de programación: se niega.
            return ProblemDetailsResponse::make(403, 'forbidden', null, null, [], ['permission' => $permission]);
        }

        $access = $this->modules->accessTo($module);
        if ($access === ModuleGate::NONE) {
            throw new ModuleNotEnabledException($module);
        }
        if ($access === ModuleGate::READ_ONLY && ! $request->isMethodSafe()) {
            // SAS-5 · RN-SAS-06: módulo retirado en transición; se consulta pero no se modifica.
            return ProblemDetailsResponse::make(403, 'module_read_only', null, null, [], ['module' => $module]);
        }

        $effective = $this->resolver->for((string) $user->getAuthIdentifier());
        if (! $effective->has($permission)) {
            return ProblemDetailsResponse::make(403, 'forbidden', null, null, [], ['permission' => $permission]);
        }

        $request->attributes->set('permission', $permission);
        $request->attributes->set('permission_scope', $effective->scopeFor($permission));
        $request->attributes->set('effective_permissions', $effective);

        return $next($request);
    }
}
