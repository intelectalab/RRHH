<?php

namespace App\Shared\Tenancy\Resolution;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Directorio de organizaciones: única puerta para buscar una organización por
 * slug, dominio o id sin contexto de organización. Vive en Shared/Tenancy porque
 * es la capa autorizada a consultar sin contexto (regla 1 de CLAUDE.md).
 *
 * `tenants` y `tenant_domains` son tablas de plataforma sin RLS.
 */
final class TenantDirectory
{
    public function findBySlug(string $slug): ?ResolvedTenant
    {
        return $this->first(
            DB::table('tenants')
                ->select(['id', 'status'])
                ->where('slug', $slug)
                ->whereNull('deleted_at'),
            'subdomain',
        );
    }

    public function findByVerifiedDomain(string $host): ?ResolvedTenant
    {
        return $this->first(
            DB::table('tenants')
                ->join('tenant_domains', 'tenant_domains.tenant_id', '=', 'tenants.id')
                ->select(['tenants.id', 'tenants.status'])
                ->where('tenant_domains.domain', $host)
                ->whereNotNull('tenant_domains.verified_at')
                ->whereNull('tenants.deleted_at'),
            'domain',
        );
    }

    public function findById(string $tenantId, string $source): ?ResolvedTenant
    {
        if (! TenancyScope::isValidUuid($tenantId)) {
            return null;
        }

        return $this->first(
            DB::table('tenants')
                ->select(['id', 'status'])
                ->where('id', $tenantId)
                ->whereNull('deleted_at'),
            $source,
        );
    }

    private function first(Builder $query, string $source): ?ResolvedTenant
    {
        /** @var object{id: string, status: string}|null $row */
        $row = TenancyScope::runWithoutTenant(fn () => $query->first());

        return $row === null ? null : new ResolvedTenant((string) $row->id, (string) $row->status, $source);
    }
}
