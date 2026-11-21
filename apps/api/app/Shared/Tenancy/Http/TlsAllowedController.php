<?php

namespace App\Shared\Tenancy\Http;

use App\Shared\Tenancy\Resolution\TenantDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * QA-17 · Consulta interna del proxy HTTPS (Caddy, «on_demand_tls ask»): responde 200 solo para los hosts de la
 * plataforma que pueden pedir certificado: el dominio base, sus subdominios reservados (admin, api, www…),
 * el subdominio de una organización existente y un dominio propio verificado. Todo lo demás, 404, para que
 * nadie pueda hacer que el servidor pida certificados para hosts ajenos. El proxy bloquea /api/internal/* desde fuera.
 */
final class TlsAllowedController
{
    public function __construct(private readonly TenantDirectory $directory) {}

    public function __invoke(Request $request): Response
    {
        $host = strtolower(trim((string) $request->query('domain', '')));
        $base = strtolower((string) config('tenancy.base_domain'));

        return response('', $this->allowed($host, $base) ? 200 : 404);
    }

    private function allowed(string $host, string $base): bool
    {
        if ($host === '' || $base === '' || strlen($host) > 253 || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }
        if ($host === $base) {
            return true;
        }
        $suffix = '.'.$base;
        if (str_ends_with($host, $suffix)) {
            $sub = substr($host, 0, -strlen($suffix));
            if ($sub === '' || str_contains($sub, '.')) {
                return false;
            }
            /** @var array<int, string> $reserved */
            $reserved = (array) config('tenancy.reserved_subdomains', []);

            return in_array($sub, $reserved, true) || $this->directory->findBySlug($sub) !== null;
        }

        return $this->directory->findByVerifiedDomain($host) !== null;
    }
}
