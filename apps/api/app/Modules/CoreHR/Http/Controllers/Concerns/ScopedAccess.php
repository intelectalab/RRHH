<?php

namespace App\Modules\CoreHR\Http\Controllers\Concerns;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda de personas y relaciones laborales con el alcance aplicado en la consulta (regla 6): fuera del
 * alcance, o de otra organización, el recurso no existe (404).
 *
 * @property DataScope $scope
 */
trait ScopedAccess
{
    /**
     * @return Builder<Person>
     */
    protected function visiblePeople(Request $request, string $permission): Builder
    {
        $query = Person::query()->whereNull('anonymized_at');
        $user = $request->user();
        $scope = $user instanceof Authenticatable ? $this->scope->employmentScope((string) $user->getAuthIdentifier(), $permission) : ['SELECT NULL::uuid WHERE false', []];
        if ($scope !== null) {
            [$sql, $bindings] = $scope;
            $query->whereRaw("id IN (SELECT person_id FROM employments WHERE id IN ({$sql}))", $bindings);
        }

        return $query;
    }

    protected function findPerson(Request $request, string $id, string $permission): Person
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $person = $this->visiblePeople($request, $permission)->whereKey($id)->first();
        abort_unless($person instanceof Person, 404);

        return $person;
    }

    protected function findEmployment(Request $request, string $id, string $permission): Employment
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = Employment::query()->whereKey($id);
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        $employment = $query->first();
        abort_unless($employment instanceof Employment, 404);

        return $employment;
    }

    /**
     * Relaciones laborales de la persona dentro del alcance (null = todas).
     *
     * @return array<int, string>|null
     */
    protected function visibleEmploymentIds(Request $request, string $personId, string $permission): ?array
    {
        $user = $request->user();
        $scope = $user instanceof Authenticatable ? $this->scope->employmentScope((string) $user->getAuthIdentifier(), $permission) : ['SELECT NULL::uuid WHERE false', []];
        if ($scope === null) {
            return null;
        }
        [$sql, $bindings] = $scope;

        return DB::table('employments')->where('person_id', $personId)->whereRaw("id IN ({$sql})", $bindings)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Persona vinculada a la cuenta de quien consulta (users.person_id), si la tiene. */
    protected function viewerPersonId(Request $request): ?string
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return null;
        }
        $personId = DB::table('users')->where('id', $user->getAuthIdentifier())->value('person_id');

        return $personId === null ? null : (string) $personId;
    }

    protected function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    protected function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : null;
    }
}
