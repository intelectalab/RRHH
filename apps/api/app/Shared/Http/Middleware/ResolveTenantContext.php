<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Http\Responses\ProblemDetailsResponse;
use App\Shared\Tenancy\Resolution\TenantResolverChain;
use App\Shared\Tenancy\TenancyScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la organización activa de la petición (docs/02-arquitectura.md §5.1).
 *
 * - La organización sale del host (subdominio o dominio propio verificado).
 *   Ningún dato que envía el cliente (cabecera, query, cuerpo) la elige, salvo
 *   la cabecera X-Tenant-Id en el entorno de pruebas.
 * - Si hay una credencial autenticada, debe pertenecer a esa organización; si no,
 *   se responde 404 sin revelar que la organización existe.
 * - Organización suspendida o bloqueada → 403 tenant_suspended; dada de baja → 403 tenant_cancelled.
 */
final class ResolveTenantContext
{
    /**
     * Rutas exentas de requerir contexto de organización.
     *
     * @var array<int, string>
     */
    private array $exemptPrefixes = [
        'api/v1/health',
        // SAS-1: la consola opera fuera de toda organización (desde un host de organización responde 404).
        'api/v1/platform/',
        // WEB-2 · WEB-4: catálogo y cotización del sitio comercial, de la plataforma y sin organización.
        'api/v1/public/catalog',
        'api/v1/public/quote',
        // WEB-5 a WEB-7: registro, leads y demos del sitio, sin organización.
        'api/v1/public/ruc/',
        'api/v1/public/subdomains/',
        'api/v1/public/signups',
        'api/v1/public/leads',
        'api/v1/public/demo-slots',
        'api/v1/public/demo-bookings',
        'api/documentation',
        'docs',
        'up',
    ];

    public function __construct(private readonly TenantResolverChain $resolvers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->resolvers->resolve($request);

        if ($resolved === null) {
            // En pruebas se admite un contexto fijado antes de la petición (TenancyScope::set).
            if (app()->environment('testing') && TenancyScope::current() !== null) {
                return $next($request);
            }

            if ($this->requiresTenant($request)) {
                return ProblemDetailsResponse::make(400, 'missing_tenant_context');
            }

            return $next($request);
        }

        if (in_array($resolved->status, (array) config('tenancy.suspended_statuses', []), true)) {
            return ProblemDetailsResponse::make(403, 'tenant_suspended');
        }

        if (in_array($resolved->status, (array) config('tenancy.cancelled_statuses', []), true)) {
            return ProblemDetailsResponse::make(403, 'tenant_cancelled');
        }

        if (! in_array($resolved->status, (array) config('tenancy.operable_statuses', []), true)) {
            return ProblemDetailsResponse::make(404, 'not_found');
        }

        if (! $this->credentialBelongsTo($request, $resolved->tenantId)) {
            return ProblemDetailsResponse::make(404, 'not_found');
        }

        TenancyScope::set($resolved->tenantId);
        $request->attributes->set('tenant_resolution_source', $resolved->source);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! app()->runningUnitTests()) {
            TenancyScope::clear();
        }
    }

    /**
     * Una sesión o token emitido para otra organización no sirve en este host.
     */
    private function credentialBelongsTo(Request $request, string $tenantId): bool
    {
        $user = $request->user();
        if ($user === null) {
            return true;
        }

        $credentialTenant = data_get($user, 'tenant_id');

        return $credentialTenant === null || (string) $credentialTenant === $tenantId;
    }

    private function requiresTenant(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ($this->exemptPrefixes as $exempt) {
            if (str_starts_with($path, $exempt)) {
                return false;
            }
        }

        return str_starts_with($path, 'api/v1');
    }
}
