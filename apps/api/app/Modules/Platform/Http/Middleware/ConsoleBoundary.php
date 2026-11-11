<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Application\Console\ConsoleSessions;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use App\Shared\Tenancy\TenancyScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAS-1 · Frontera de la consola (regla 1): sus rutas solo responden fuera del contexto de una
 * organización. Desde el host de una organización (o con una organización fijada) responden 404,
 * sin revelar que existen. Además aplica el doble envío XSRF de la cookie propia de la consola.
 */
final class ConsoleBoundary
{
    public const ALIAS = 'platform.console';

    public function handle(Request $request, Closure $next): Response
    {
        if (TenancyScope::current() !== null || $request->attributes->has('tenant_resolution_source')) {
            return ProblemDetailsResponse::make(404, 'not_found');
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $token = $request->cookie((string) config('platform.session_cookie'));
            if (is_string($token) && $token !== '') {
                $header = (string) $request->header('X-XSRF-TOKEN', '');
                if ($header === '' || ! hash_equals(ConsoleSessions::xsrfFor($token), $header)) {
                    return ProblemDetailsResponse::make(403, 'csrf_mismatch');
                }
            }
        }

        return $next($request);
    }
}
