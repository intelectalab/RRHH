<?php

namespace App\Shared\Features;

use App\Shared\Exceptions\AyniException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAS-7 · `->middleware('feature:<código>')`: la ruta solo responde si la bandera está activa para la
 * organización (403 feature_not_enabled). Lo que la interfaz oculta, el backend lo valida (regla 10).
 */
final class RequireFeature
{
    public const ALIAS = 'feature';

    public function __construct(private readonly FeatureFlags $flags) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $code): Response
    {
        if (! $this->flags->enabled($code)) {
            throw new AyniException('feature_not_enabled', 403, (string) trans('errors.feature_not_enabled'), [], ['feature' => $code]);
        }

        return $next($request);
    }
}
