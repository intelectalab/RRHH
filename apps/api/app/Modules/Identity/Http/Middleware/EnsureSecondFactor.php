<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\Mfa\MfaService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEG-3 · RN-SEG-08: una sesión abierta con la clave no opera hasta superar el segundo paso
 * (o configurarlo, si su rol o la organización lo exige). Mientras tanto solo responden las
 * rutas del propio segundo paso; el resto devuelve 401 mfa_required con meta.step.
 */
final class EnsureSecondFactor
{
    /** Rutas disponibles con el segundo paso pendiente (método y URI de Laravel). */
    private const ALLOWED = [
        'GET api/v1/me',
        'POST api/v1/auth/logout',
        'POST api/v1/auth/mfa/verify',
        'GET api/v1/me/mfa',
        'POST api/v1/me/mfa/totp',
        'POST api/v1/me/mfa/totp/confirm',
    ];

    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $key = $request->getMethod().' '.(is_object($route) ? $route->uri() : '');
        // Las rutas públicas (ingreso, recuperar clave) no dependen de la sesión.
        $protected = is_object($route) && in_array('auth:ayni', $route->gatherMiddleware(), true);
        if (! $protected || in_array($key, self::ALLOWED, true)) {
            return $next($request);
        }

        $user = $request->user();
        $session = $request->attributes->get('ayni_session');
        if (! $user instanceof User || ! $session instanceof UserSession) {
            return $next($request);
        }

        $status = $this->mfa->sessionStatus($user, $session);
        if ($status === 'ok') {
            return $next($request);
        }

        return ProblemDetailsResponse::make(401, 'mfa_required', null, null, [], ['step' => $status, 'methods' => ['totp', 'recovery_code']]);
    }
}
