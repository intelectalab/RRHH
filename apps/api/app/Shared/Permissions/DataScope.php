<?php

namespace App\Shared\Permissions;

use App\Shared\Exceptions\AyniException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    /**
     * SEG-10 · Relaciones laborales visibles para un permiso, a la fecha indicada.
     *
     * Devuelve null si el alcance es toda la organización; si no, una subconsulta SQL
     * (con sus valores) que selecciona los employment_id visibles, unión de:
     *  - empresa: relaciones de esas razones sociales;
     *  - sede: relaciones cuya asignación vigente está en esas sedes;
     *  - unidad: relaciones en esas unidades y sus descendientes;
     *  - equipo: reportes directos e indirectos según la línea vigente (jefe por posición,
     *    con excepción por relación cuando la asignación indica manager_employment_id);
     *  - propio: las relaciones de la propia persona.
     * Se calcula en cada consulta, así que un cambio de estructura o de jefe aplica solo.
     *
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    public function employmentScope(string $userId, string $permission, ?string $asOf = null): ?array
    {
        $date = $asOf ?? now()->toDateString();
        $grants = $this->grants($userId, $permission);

        if ($grants->contains(fn ($g) => $g->scope === 'organization')) {
            return null;
        }

        $current = '(a.valid_from <= ?::date AND (a.valid_to IS NULL OR a.valid_to >= ?::date))';
        $parts = [];
        $bindings = [];

        foreach ($grants as $grant) {
            switch ($grant->scope) {
                case 'legal_entity':
                    $parts[] = 'SELECT e.id FROM employments e JOIN user_role_scopes s ON s.legal_entity_id = e.legal_entity_id WHERE s.user_role_id = ?';
                    $bindings[] = $grant->user_role_id;
                    break;
                case 'site':
                    $parts[] = "SELECT a.employment_id FROM employment_assignments a JOIN user_role_scopes s ON s.site_id = a.site_id WHERE s.user_role_id = ? AND {$current}";
                    array_push($bindings, $grant->user_role_id, $date, $date);
                    break;
                case 'org_unit':
                    $parts[] = 'SELECT a.employment_id FROM employment_assignments a WHERE a.org_unit_id IN ('
                        .'WITH RECURSIVE tree AS (SELECT u.id FROM org_units u JOIN user_role_scopes s ON s.org_unit_id = u.id WHERE s.user_role_id = ? '
                        .'UNION SELECT c.id FROM org_units c JOIN tree t ON c.parent_id = t.id) SELECT id FROM tree'
                        .") AND {$current}";
                    array_push($bindings, $grant->user_role_id, $date, $date);
                    break;
                case 'team':
                    $parts[] = 'WITH RECURSIVE mine AS ('
                        .'SELECT e.id AS emp_id, a.position_id AS pos_id FROM employments e JOIN users u ON u.person_id = e.person_id '
                        ."JOIN employment_assignments a ON a.employment_id = e.id AND {$current} "
                        .'WHERE (u.id = ? OR u.id IN ('.TeamLine::DELEGATORS.")) AND e.status IN ('active','suspended','terminating')"
                        .'), team AS ('
                        .'SELECT emp_id, pos_id FROM mine '
                        .'UNION '
                        .'SELECT a.employment_id, a.position_id FROM employment_assignments a '
                        .'LEFT JOIN positions p ON p.id = a.position_id '
                        .'JOIN team t ON (a.manager_employment_id = t.emp_id OR (a.manager_employment_id IS NULL AND p.reports_to_position_id = t.pos_id)) '
                        ."WHERE {$current}"
                        .') SELECT emp_id FROM team WHERE emp_id NOT IN (SELECT emp_id FROM mine)';
                    // RN-MSS-05 · El suplente con una delegación vigente ve también el equipo de quien delegó.
                    array_push($bindings, $date, $date, $userId, $userId, $date, $date, $date, $date);
                    break;
                case 'own':
                    $parts[] = 'SELECT e.id FROM employments e JOIN users u ON u.person_id = e.person_id WHERE u.id = ?';
                    $bindings[] = $userId;
                    break;
                default:
                    // assigned: no aplica a relaciones laborales.
                    break;
            }
        }

        if ($parts === []) {
            return ['SELECT NULL::uuid WHERE false', []];
        }

        return [implode(' UNION ', array_map(fn (string $p) => "({$p})", $parts)), $bindings];
    }

    /**
     * Aplica el alcance de relaciones laborales a una consulta sobre `$column` (employment_id).
     *
     * @template TBuilder of EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyEmployment(EloquentBuilder|QueryBuilder $query, Request $request, string $permission, string $column = 'employment_id', ?string $asOf = null): EloquentBuilder|QueryBuilder
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AyniException('unauthenticated', 401);
        }

        $scope = $this->employmentScope((string) $user->getAuthIdentifier(), $permission, $asOf);
        if ($scope !== null) {
            [$sql, $bindings] = $scope;
            $query->whereRaw("{$column} IN ({$sql})", $bindings);
        }

        return $query;
    }

    /**
     * @return Collection<int, object{user_role_id: string, scope: string}>
     */
    private function grants(string $userId, string $permission): Collection
    {
        $today = now()->toDateString();

        /** @var Collection<int, object{user_role_id: string, scope: string}> */
        return DB::table('user_roles as ur')
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
