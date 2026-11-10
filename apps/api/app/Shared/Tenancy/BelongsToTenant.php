<?php

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Primera barrera de aislamiento (docs/02-arquitectura.md §5.2): ámbito global
 * `where tenant_id = ?` y asignación automática al crear. Sin organización activa
 * la consulta lanza TenantContextRequiredException (no devuelve todo).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), TenancyScope::requireTenant());
        });

        static::creating(static function (Model $model): void {
            $tenantId = TenancyScope::requireTenant();
            $current = $model->getAttribute('tenant_id');

            if ($current === null) {
                $model->setAttribute('tenant_id', $tenantId);
            } elseif ((string) $current !== $tenantId) {
                throw new TenantContextRequiredException('No se puede crear un registro para otra organización.');
            }
        });
    }
}
