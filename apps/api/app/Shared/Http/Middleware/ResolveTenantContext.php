<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Tenancy\TenancyScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    /**
     * Rutas exentas de requerir contexto de organización obligatorio.
     *
     * @var array<int, string>
     */
    private array $exemptPrefixes = [
        'api/v1/health',
        'api/documentation',
        'docs',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId !== null) {
            TenancyScope::set($tenantId);
        } elseif (TenancyScope::current() !== null) {
            // Ya hay un contexto de organización activo (ej. sesión, token previo o prueba)
            $tenantId = TenancyScope::current();
        } elseif ($this->requiresTenant($request)) {
            return response()->json([
                'error' => [
                    'code' => 'missing_tenant_context',
                    'message' => 'Se requiere la cabecera X-Tenant-Id o un contexto de organización activo.',
                ],
            ], 400);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! app()->runningUnitTests()) {
            TenancyScope::clear();
        }
    }

    private function resolveTenantId(Request $request): ?string
    {
        $header = $request->header('X-Tenant-Id');
        if (is_string($header) && TenancyScope::isValidUuid($header)) {
            return $header;
        }

        if ($request->has('tenant_id')) {
            $queryTenant = (string) $request->input('tenant_id');
            if (TenancyScope::isValidUuid($queryTenant)) {
                return $queryTenant;
            }
        }

        return null;
    }

    private function requiresTenant(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ($this->exemptPrefixes as $exempt) {
            if (str_starts_with($path, $exempt)) {
                return false;
            }
        }

        // Cualquier ruta bajo api/v1 que no esté exenta exige contexto de organización
        return str_starts_with($path, 'api/v1');
    }
}
