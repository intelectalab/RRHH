<?php

namespace App\Shared\Usage;

use App\Shared\Tenancy\TenancyScope;
use App\Shared\Usage\Sources\ApiCallsUsageSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SAS-6 · Cuenta las llamadas a la API de cada organización (solo peticiones con sesión). Suma en la caché;
 * una falla al contar nunca rompe la petición.
 */
final class MeterApiUsage
{
    public function __construct(private readonly UsageRegistry $registry) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (TenancyScope::current() !== null && $request->user() !== null) {
            $source = $this->registry->get(UsageMetrics::API_CALLS);
            if ($source instanceof ApiCallsUsageSource) {
                try {
                    $source->increment();
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $response;
    }
}
