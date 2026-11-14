<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\MyTeam;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Query\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MSS-2 · GET /my-team: equipo directo e indirecto del manager con acceso a la ficha permitida.
 */
final class MyTeamController
{
    private const FILTERS = ['level'];

    public function __construct(private readonly MyTeam $team) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter');
        if (is_array($filter) && array_diff(array_keys($filter), self::FILTERS) !== []) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $level = TableQuery::filter($request, 'level');
        if ($level !== null && ! in_array($level, ['direct', 'indirect'], true)) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $permissions = $request->attributes->get('effective_permissions');
        $permissions = $permissions instanceof EffectivePermissions ? $permissions : new EffectivePermissions([], [], []);

        return response()->json($this->team->list(
            (string) $request->user()?->getAuthIdentifier(),
            $permissions,
            now()->toDateString(),
            ['level' => $level, 'q' => is_string($request->query('q')) ? $request->query('q') : null],
        ));
    }
}
