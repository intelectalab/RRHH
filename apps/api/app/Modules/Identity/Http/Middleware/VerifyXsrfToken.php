<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\SessionManager;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protección CSRF de doble envío (docs/08-api.md): toda escritura autenticada con
 * la cookie de sesión debe traer X-XSRF-TOKEN igual al derivado de esa sesión.
 */
final class VerifyXsrfToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // La consola SaaS verifica su propia cookie y su propio token (SAS-1).
        if (str_starts_with($request->path(), 'api/v1/platform/')) {
            return $next($request);
        }

        $sessionToken = $request->cookie((string) config('identity.session_cookie'));
        if (! is_string($sessionToken) || $sessionToken === '') {
            return $next($request); // sin cookie de sesión no hay CSRF que proteger
        }

        $header = (string) $request->header('X-XSRF-TOKEN', '');
        if ($header === '' || ! hash_equals(SessionManager::xsrfFor($sessionToken), $header)) {
            return ProblemDetailsResponse::make(403, 'csrf_mismatch');
        }

        return $next($request);
    }
}
