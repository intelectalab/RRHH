<?php

namespace App\Shared\Permissions;

use App\Shared\Exceptions\AyniException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Alcance de datos por empresa (TEN-5 · SEG-10). Se aplica en la consulta, no en la vista.
 *
 * Para un permiso, devuelve las empresas (razones sociales) que el usuario puede ver:
 *  - null  → todas (alguno de sus roles concede el permiso con alcance de organización);
 *  - lista → las de sus asignaciones con alcance de empresa o sede, más las de sus
 *            propias relaciones laborales para los alcances unidad, equipo y propio.
 *
 * El filtro de empresa elegido en la barra superior (`legal_entity_id`) se valida
 * contra ese alcance: una empresa fuera de alcance responde «no encontrado».
 */
final class DataScope
{
    /** @var array<string, array<int, string>|null> */
    private array $memo = [];

    /**
     * @return array<int, string>|null
     */
    public function legalEntityIds(string $userId, string $permission): ?array
    {
        $key = $userId.'|'.$permission;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $today = now()->toDateString();
        $grants = DB::table('user_roles as ur')
            ->join('roles as r', function ($j): void {
                $j->on('r.id', '=', 'ur.role_id')->on('r.tenant_id', '=', 'ur.tenant_id');
            })
            ->join('role_permissions as rp', function ($j): void {
                $j->on('rp.role_id', '=', 'r.id')->on('rp.tenant_id', '=', 'r.tenant_id');
            })
            ->where('ur.user_id', $userId)
            ->where('rp.permission_code', $permission)
            ->whereNull('r.deleted_at')
            ->where(fn ($q) => $q->whereNull('ur.valid_from')->orWhere('ur.valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ur.valid_to')->orWhere('ur.valid_to', '>=', $today))
            ->get(['ur.id as user_role_id', DB::raw('COALESCE(ur.scope_override, rp.scope) as scope')]);

        if ($grants->contains(fn ($g) => $g->scope === 'organization')) {
            return $this->memo[$key] = null;
        }

        $ids = [];
        $userRoleIds = $grants->whereIn('scope', ['legal_entity', 'site'])->pluck('user_role_id')->all();
        if ($userRoleIds !== []) {
            $ids = array_merge(
                $ids,
                DB::table('user_role_scopes')->whereIn('user_role_id', $userRoleIds)->whereNotNull('legal_entity_id')->pluck('legal_entity_id')->all(),
                DB::table('user_role_scopes as s')->join('sites', 'sites.id', '=', 's.site_id')
                    ->whereIn('s.user_role_id', $userRoleIds)->whereNotNull('sites.legal_entity_id')->pluck('sites.legal_entity_id')->all(),
            );
        }

        if ($grants->whereIn('scope', ['org_unit', 'team', 'own', 'assigned'])->isNotEmpty()) {
            $personId = DB::table('users')->where('id', $userId)->value('person_id');
            if ($personId !== null) {
                $ids = array_merge($ids, DB::table('employments')
                    ->where('person_id', $personId)
                    ->whereIn('status', ['active', 'suspended', 'terminating'])
                    ->pluck('legal_entity_id')->all());
            }
        }

        $ids = array_values(array_unique(array_map(fn ($id) => (string) $id, $ids)));
        sort($ids);

        return $this->memo[$key] = $ids;
    }

    /**
     * Aplica alcance y filtro de empresa a una consulta sobre `$column`.
     *
     * @template TBuilder of EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyLegalEntity(EloquentBuilder|QueryBuilder $query, Request $request, string $permission, string $column = 'legal_entity_id'): EloquentBuilder|QueryBuilder
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AyniException('unauthenticated', 401);
        }

        $allowed = $this->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
        $selected = self::selectedLegalEntity($request);

        if ($selected !== null) {
            if ($allowed !== null && ! in_array($selected, $allowed, true)) {
                abort(404);
            }
            $query->where($column, $selected);
        } elseif ($allowed !== null) {
            $query->whereIn($column, $allowed);
        }

        return $query;
    }

    public static function selectedLegalEntity(Request $request): ?string
    {
        $filter = $request->query('filter');
        $value = is_array($filter) && isset($filter['legal_entity_id']) ? $filter['legal_entity_id'] : $request->query('legal_entity_id');
        if (! is_string($value) || $value === '' || $value === 'all') {
            return null;
        }
        if (preg_match('/^[0-9a-f-]{36}$/i', $value) !== 1) {
            abort(404);
        }

        return strtolower($value);
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
