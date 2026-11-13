<?php

namespace App\Modules\CoreHR\Http\Controllers\Concerns;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\Request;

/**
 * Las secciones de la ficha (derechohabientes, previsión, seguros, cuentas) cuelgan de una relación laboral:
 * se ubica con el alcance del usuario aplicado en la consulta (regla 6). Fuera del alcance o de otra
 * organización, 404.
 *
 * @property-read DataScope $scope
 */
trait ResolvesEmployment
{
    protected function employment(Request $request, string $id, string $permission): Employment
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = Employment::query()->whereKey($id);
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        $employment = $query->first();
        abort_unless($employment instanceof Employment, 404);

        return $employment;
    }

    protected function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    protected function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getAuthIdentifier();
    }

    protected static function uuidOr404(string $id): void
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
    }
}
