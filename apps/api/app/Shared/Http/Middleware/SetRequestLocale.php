<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Tenancy\TenancyScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * CFG-6 · Idioma de la respuesta (mensajes de error, etiquetas de catálogos, textos del servidor):
 * 1) preferencia del usuario, 2) idioma por defecto de la organización, 3) Accept-Language,
 * 4) español. Se resuelve en cada solicitud: cambiar de idioma no exige volver a iniciar sesión.
 */
final class SetRequestLocale
{
    public const LOCALES = ['es', 'en', 'pt'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }

    private function resolve(Request $request): string
    {
        $route = $request->route();
        $protected = is_object($route) && in_array('auth:ayni', $route->gatherMiddleware(), true);
        if ($protected) {
            $user = $request->user();
            $locale = is_object($user) ? ($user->locale ?? null) : null;
            if (is_string($locale) && in_array($locale, self::LOCALES, true)) {
                return $locale;
            }
            if (TenancyScope::current() !== null) {
                $tenant = DB::table('current_tenant')->value('default_locale');
                if (is_string($tenant) && in_array($tenant, self::LOCALES, true)) {
                    return $tenant;
                }
            }
        }

        $preferred = $request->getPreferredLanguage(self::LOCALES);

        return is_string($preferred) && in_array($preferred, self::LOCALES, true) ? $preferred : 'es';
    }
}
